//! Installation, lancement et mise à jour des applications du catalogue.
//!
//! Le launcher tient un petit registre (`installed.json` dans son dossier de données)
//! des applis qu'il a installées : version, type de fichier, chemin à lancer.
//! Chaque téléchargement est vérifié (SHA-256) avant toute installation.

use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::io::Write;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::Mutex;
use std::time::{Instant, SystemTime, UNIX_EPOCH};
use tauri::{AppHandle, Emitter, Manager, Runtime, State};

/// Empêche deux installations simultanées de la même appli et protège le registre.
#[derive(Default)]
pub struct AppsState {
    busy: Mutex<Vec<String>>,
    registry: Mutex<()>,
}

#[derive(Serialize, Deserialize, Clone, Debug)]
pub struct InstalledApp {
    pub version: String,
    pub kind: String,
    /// Ce qu'il faut lancer (exécutable, .app, AppImage) — absolu, variables déjà remplacées
    pub launch: Option<String>,
    /// Dossier géré par le launcher (applis portables / AppImage), supprimé à la désinstallation
    pub dir: Option<String>,
    pub installed_at: u64,
}

#[derive(Serialize, Clone, Debug)]
pub struct InstalledView {
    #[serde(flatten)]
    pub app: InstalledApp,
    /// Le programme est-il toujours présent sur le disque ?
    pub exists: bool,
    /// Le launcher peut-il le désinstaller lui-même ?
    pub removable: bool,
}

#[derive(Deserialize)]
pub struct InstallRequest {
    pub slug: String,
    pub version: String,
    pub url: String,
    pub sha256: Option<String>,
    pub kind: String,
    pub install_args: Option<String>,
    pub launch_path: Option<String>,
}

#[derive(Serialize, Clone)]
struct Progress<'a> {
    slug: &'a str,
    phase: &'a str, // download | verify | install | done | error
    done: u64,
    total: u64,
}

type R<T> = Result<T, String>;

fn e2s<E: std::fmt::Display>(e: E) -> String {
    e.to_string()
}

fn now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

/// Un slug sert de nom de dossier : on n'accepte que [a-z0-9-]
fn check_slug(slug: &str) -> R<()> {
    if slug.is_empty() || slug.len() > 64 || !slug.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-') {
        return Err("Identifiant d'appli invalide".into());
    }
    Ok(())
}

// ---------- Dossiers et registre ----------

fn data_dir<Rt: Runtime>(app: &AppHandle<Rt>) -> R<PathBuf> {
    let d = app.path().app_data_dir().map_err(e2s)?;
    fs::create_dir_all(&d).map_err(e2s)?;
    Ok(d)
}

fn apps_root<Rt: Runtime>(app: &AppHandle<Rt>) -> R<PathBuf> {
    let d = data_dir(app)?.join("apps");
    fs::create_dir_all(&d).map_err(e2s)?;
    Ok(d)
}

fn downloads_dir<Rt: Runtime>(app: &AppHandle<Rt>) -> R<PathBuf> {
    let d = app.path().app_cache_dir().map_err(e2s)?.join("downloads");
    fs::create_dir_all(&d).map_err(e2s)?;
    Ok(d)
}

fn load_registry<Rt: Runtime>(app: &AppHandle<Rt>) -> BTreeMap<String, InstalledApp> {
    data_dir(app)
        .ok()
        .and_then(|d| fs::read_to_string(d.join("installed.json")).ok())
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or_default()
}

fn save_registry<Rt: Runtime>(app: &AppHandle<Rt>, reg: &BTreeMap<String, InstalledApp>) -> R<()> {
    let path = data_dir(app)?.join("installed.json");
    let tmp = path.with_extension("json.tmp");
    fs::write(&tmp, serde_json::to_vec_pretty(reg).map_err(e2s)?).map_err(e2s)?;
    fs::rename(&tmp, &path).map_err(e2s)
}

/// Remplace %VAR% (Windows), $VAR / ${VAR} et ~ (macOS, Linux) dans un chemin
fn expand_vars(input: &str) -> String {
    let mut s = input.trim().to_string();
    if s.starts_with('~') {
        if let Some(home) = std::env::var_os("HOME").or_else(|| std::env::var_os("USERPROFILE")) {
            s = format!("{}{}", home.to_string_lossy(), &s[1..]);
        }
    }
    // %VAR%
    let mut out = String::new();
    let mut rest = s.as_str();
    while let Some(start) = rest.find('%') {
        out.push_str(&rest[..start]);
        let after = &rest[start + 1..];
        match after.find('%') {
            Some(end) => {
                let name = &after[..end];
                match std::env::var(name) {
                    Ok(v) if !name.is_empty() => out.push_str(&v),
                    _ => out.push_str(&rest[start..start + end + 2]),
                }
                rest = &after[end + 1..];
            }
            None => {
                out.push_str(&rest[start..]);
                rest = "";
            }
        }
    }
    out.push_str(rest);
    // $VAR et ${VAR}
    let mut res = String::new();
    let chars: Vec<char> = out.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        if chars[i] == '$' && i + 1 < chars.len() {
            let braced = chars[i + 1] == '{';
            let mut j = if braced { i + 2 } else { i + 1 };
            let st = j;
            while j < chars.len() && (chars[j].is_ascii_alphanumeric() || chars[j] == '_') {
                j += 1;
            }
            let name: String = chars[st..j].iter().collect();
            if !name.is_empty() && (!braced || (j < chars.len() && chars[j] == '}')) {
                if let Ok(v) = std::env::var(&name) {
                    res.push_str(&v);
                    i = if braced { j + 1 } else { j };
                    continue;
                }
            }
        }
        res.push(chars[i]);
        i += 1;
    }
    res
}

fn view(a: InstalledApp) -> InstalledView {
    let exists = a.launch.as_deref().map(|p| Path::new(p).exists()).unwrap_or(true);
    let removable = a.dir.is_some();
    InstalledView { app: a, exists, removable }
}

// ---------- Commandes ----------

#[tauri::command]
pub fn installed_apps<Rt: Runtime>(app: AppHandle<Rt>) -> BTreeMap<String, InstalledView> {
    load_registry(&app).into_iter().map(|(k, v)| (k, view(v))).collect()
}

#[tauri::command]
pub fn apps_folder<Rt: Runtime>(app: AppHandle<Rt>) -> R<String> {
    Ok(apps_root(&app)?.to_string_lossy().into_owned())
}

#[tauri::command]
pub async fn install_app<Rt: Runtime>(app: AppHandle<Rt>, state: State<'_, AppsState>, req: InstallRequest) -> R<InstalledView> {
    check_slug(&req.slug)?;
    let local = req.url.starts_with("http://127.0.0.1") || req.url.starts_with("http://localhost");
    if !req.url.starts_with("https://") && !local {
        return Err("Téléchargement refusé : l'adresse doit être en https".into());
    }
    {
        let mut busy = state.busy.lock().map_err(e2s)?;
        if busy.contains(&req.slug) {
            return Err("Installation déjà en cours".into());
        }
        busy.push(req.slug.clone());
    }
    let slug = req.slug.clone();
    let result = do_install(&app, &state, req).await;
    if let Ok(mut busy) = state.busy.lock() {
        busy.retain(|s| s != &slug);
    }
    let phase = if result.is_ok() { "done" } else { "error" };
    let _ = app.emit("nl-progress", Progress { slug: &slug, phase, done: 0, total: 0 });
    result
}

async fn do_install<Rt: Runtime>(app: &AppHandle<Rt>, state: &State<'_, AppsState>, req: InstallRequest) -> R<InstalledView> {
    let file = download(app, &req).await?;

    let _ = app.emit("nl-progress", Progress { slug: &req.slug, phase: "install", done: 0, total: 0 });
    let app2 = app.clone();
    let installed = tauri::async_runtime::spawn_blocking(move || install_file(&app2, &req, &file))
        .await
        .map_err(e2s)??;

    let _guard = state.registry.lock().map_err(e2s)?;
    let mut reg = load_registry(app);
    reg.insert(installed.0.clone(), installed.1.clone());
    save_registry(app, &reg)?;
    Ok(view(installed.1))
}

/// Télécharge en flux vers le cache, avec progression et vérification SHA-256
async fn download<Rt: Runtime>(app: &AppHandle<Rt>, req: &InstallRequest) -> R<PathBuf> {
    let name = req.url.rsplit('/').next().unwrap_or("fichier").split('?').next().unwrap_or("fichier");
    let safe: String = name.chars().map(|c| if c.is_ascii_alphanumeric() || ".-_".contains(c) { c } else { '_' }).collect();
    let path = downloads_dir(app)?.join(format!("{}-{}", req.slug, safe));
    let part = path.with_extension("part");

    let client = reqwest::Client::builder()
        .user_agent(concat!("NounoursLauncher/", env!("CARGO_PKG_VERSION")))
        .connect_timeout(std::time::Duration::from_secs(15))
        .build()
        .map_err(e2s)?;
    let resp = client.get(&req.url).send().await.map_err(|e| format!("Téléchargement impossible : {e}"))?;
    if !resp.status().is_success() {
        return Err(format!("Téléchargement impossible (erreur {})", resp.status().as_u16()));
    }
    let total = resp.content_length().unwrap_or(0);
    let mut out = fs::File::create(&part).map_err(e2s)?;
    let mut hasher = Sha256::new();
    let mut done: u64 = 0;
    let mut last = Instant::now();
    let mut stream = resp.bytes_stream();
    while let Some(chunk) = stream.next().await {
        let chunk = chunk.map_err(|e| format!("Connexion interrompue : {e}"))?;
        out.write_all(&chunk).map_err(e2s)?;
        hasher.update(&chunk);
        done += chunk.len() as u64;
        if last.elapsed().as_millis() > 150 {
            last = Instant::now();
            let _ = app.emit("nl-progress", Progress { slug: &req.slug, phase: "download", done, total });
        }
    }
    out.flush().map_err(e2s)?;
    drop(out);

    let _ = app.emit("nl-progress", Progress { slug: &req.slug, phase: "verify", done, total });
    let got = hex::encode(hasher.finalize());
    if let Some(expected) = req.sha256.as_deref().filter(|s| !s.is_empty()) {
        if !got.eq_ignore_ascii_case(expected) {
            let _ = fs::remove_file(&part);
            return Err("Fichier corrompu ou modifié (empreinte SHA-256 différente) : installation annulée".into());
        }
    }
    let _ = fs::remove_file(&path);
    fs::rename(&part, &path).map_err(e2s)?;
    Ok(path)
}

/// Installe selon le type de fichier ; renvoie l'entrée du registre
fn install_file<Rt: Runtime>(app: &AppHandle<Rt>, req: &InstallRequest, file: &Path) -> R<(String, InstalledApp)> {
    let launch_hint = req.launch_path.as_deref().map(expand_vars).filter(|s| !s.is_empty());
    let mut entry = InstalledApp {
        version: req.version.clone(),
        kind: req.kind.clone(),
        launch: None,
        dir: None,
        installed_at: now(),
    };
    let lower = file.to_string_lossy().to_lowercase();

    match req.kind.as_str() {
        // Archive zip ou exécutable portable : installé dans le dossier du launcher
        "zip" | "portable" => {
            let root = apps_root(app)?.join(&req.slug);
            let staging = apps_root(app)?.join(format!(".{}-new", req.slug));
            let _ = fs::remove_dir_all(&staging);
            fs::create_dir_all(&staging).map_err(e2s)?;
            if lower.ends_with(".zip") {
                extract_zip(file, &staging)?;
            } else {
                let name = file.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
                let name = name.splitn(2, '-').nth(1).unwrap_or(&name).to_string(); // retire le préfixe "slug-"
                fs::copy(file, staging.join(&name)).map_err(e2s)?;
                make_executable(&staging.join(&name));
            }
            // Remplacement atomique de l'ancienne version
            let _ = fs::remove_dir_all(&root);
            fs::rename(&staging, &root).map_err(e2s)?;
            let target = match launch_hint {
                Some(rel) if !Path::new(&rel).is_absolute() => Some(root.join(rel)),
                Some(abs) => Some(PathBuf::from(abs)),
                None => find_launchable(&root),
            };
            if let Some(t) = &target {
                make_executable(t);
            }
            entry.launch = target.map(|p| p.to_string_lossy().into_owned());
            entry.dir = Some(root.to_string_lossy().into_owned());
            let _ = fs::remove_file(file);
        }

        // AppImage (Linux) : un seul fichier exécutable
        "appimage" | "updater" if lower.ends_with(".appimage") => {
            let root = apps_root(app)?.join(&req.slug);
            fs::create_dir_all(&root).map_err(e2s)?;
            let dest = root.join(format!("{}.AppImage", req.slug));
            let tmp = root.join(".new.AppImage");
            fs::copy(file, &tmp).map_err(e2s)?;
            make_executable(&tmp);
            fs::rename(&tmp, &dest).map_err(e2s)?;
            entry.launch = Some(dest.to_string_lossy().into_owned());
            entry.dir = Some(root.to_string_lossy().into_owned());
            let _ = fs::remove_file(file);
        }

        // Installeur Windows (NSIS, Inno Setup…)
        "installer" if cfg!(windows) => {
            let args = split_args(req.install_args.as_deref().unwrap_or(""));
            run_windows_installer(file, &args)?;
            entry.launch = launch_hint;
            let _ = fs::remove_file(file);
        }
        "msi" if cfg!(windows) => {
            let mut args = vec!["/i".to_string(), file.to_string_lossy().into_owned(), "/passive".into(), "/norestart".into()];
            args.extend(split_args(req.install_args.as_deref().unwrap_or("")));
            let st = Command::new("msiexec").args(&args).status().map_err(e2s)?;
            if !st.success() {
                return Err(format!("L'installation MSI a échoué (code {})", st.code().unwrap_or(-1)));
            }
            entry.launch = launch_hint;
            let _ = fs::remove_file(file);
        }

        // Image disque macOS : on copie le .app dans ~/Applications
        "dmg" if cfg!(target_os = "macos") => {
            let app_path = install_dmg(file)?;
            entry.launch = Some(launch_hint.unwrap_or(app_path));
            let _ = fs::remove_file(file);
        }

        // Autres cas (.deb, .rpm, .jar, installeur d'une autre plateforme…) :
        // on ouvre le fichier avec le programme du système et on garde la trace de la version.
        _ => {
            open_with_system(file)?;
            entry.launch = launch_hint;
        }
    }
    Ok((req.slug.clone(), entry))
}

fn split_args(s: &str) -> Vec<String> {
    // Découpe simple qui respecte les guillemets : /D="C:\Program Files\X" /S
    let mut out = Vec::new();
    let mut cur = String::new();
    let mut quoted = false;
    for c in s.chars() {
        match c {
            '"' => quoted = !quoted,
            ' ' if !quoted => {
                if !cur.is_empty() {
                    out.push(std::mem::take(&mut cur));
                }
            }
            _ => cur.push(c),
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

#[cfg(windows)]
fn run_windows_installer(file: &Path, args: &[String]) -> R<()> {
    match Command::new(file).args(args).status() {
        Ok(st) if st.success() => Ok(()),
        Ok(st) => Err(format!("L'installeur s'est terminé avec une erreur (code {})", st.code().unwrap_or(-1))),
        // 740 = l'installeur demande les droits administrateur → on relance avec la fenêtre UAC
        Err(e) if e.raw_os_error() == Some(740) => {
            let quoted: Vec<String> = args.iter().map(|a| format!("'{}'", a.replace('\'', "''"))).collect();
            let arglist = if quoted.is_empty() { String::new() } else { format!(" -ArgumentList {}", quoted.join(",")) };
            let ps = format!(
                "$p = Start-Process -FilePath '{}'{} -Verb RunAs -Wait -PassThru; exit $p.ExitCode",
                file.to_string_lossy().replace('\'', "''"),
                arglist
            );
            let st = Command::new("powershell")
                .args(["-NoProfile", "-NonInteractive", "-Command", &ps])
                .status()
                .map_err(e2s)?;
            if st.success() { Ok(()) } else { Err("Installation annulée ou refusée".into()) }
        }
        Err(e) => Err(format!("Impossible de lancer l'installeur : {e}")),
    }
}
#[cfg(not(windows))]
fn run_windows_installer(_: &Path, _: &[String]) -> R<()> {
    Err("Installeur Windows".into())
}

#[cfg(target_os = "macos")]
fn install_dmg(file: &Path) -> R<String> {
    let mount = std::env::temp_dir().join(format!("nl-dmg-{}", now()));
    fs::create_dir_all(&mount).map_err(e2s)?;
    let st = Command::new("hdiutil")
        .args(["attach", "-nobrowse", "-readonly", "-mountpoint"])
        .arg(&mount)
        .arg(file)
        .status()
        .map_err(e2s)?;
    if !st.success() {
        return Err("Impossible d'ouvrir l'image disque".into());
    }
    let result = (|| {
        let app_src = fs::read_dir(&mount)
            .map_err(e2s)?
            .flatten()
            .map(|e| e.path())
            .find(|p| p.extension().map(|x| x == "app").unwrap_or(false))
            .ok_or("Aucune application trouvée dans l'image disque")?;
        let home = std::env::var("HOME").map_err(e2s)?;
        let dest_dir = PathBuf::from(home).join("Applications");
        fs::create_dir_all(&dest_dir).map_err(e2s)?;
        let dest = dest_dir.join(app_src.file_name().unwrap());
        let _ = fs::remove_dir_all(&dest);
        let st = Command::new("ditto").arg(&app_src).arg(&dest).status().map_err(e2s)?;
        if !st.success() {
            return Err("La copie de l'application a échoué".to_string());
        }
        Ok(dest.to_string_lossy().into_owned())
    })();
    let _ = Command::new("hdiutil").args(["detach", "-quiet"]).arg(&mount).status();
    let _ = fs::remove_dir(&mount);
    result
}
#[cfg(not(target_os = "macos"))]
fn install_dmg(_: &Path) -> R<String> {
    Err("Image disque macOS".into())
}

fn extract_zip(file: &Path, dest: &Path) -> R<()> {
    let f = fs::File::open(file).map_err(e2s)?;
    let mut zip = zip::ZipArchive::new(f).map_err(|e| format!("Archive illisible : {e}"))?;
    for i in 0..zip.len() {
        let mut entry = zip.by_index(i).map_err(e2s)?;
        // enclosed_name() refuse les chemins qui sortiraient du dossier (../)
        let Some(rel) = entry.enclosed_name() else { continue };
        let out = dest.join(rel);
        if entry.is_dir() {
            fs::create_dir_all(&out).map_err(e2s)?;
            continue;
        }
        if let Some(parent) = out.parent() {
            fs::create_dir_all(parent).map_err(e2s)?;
        }
        let mut w = fs::File::create(&out).map_err(e2s)?;
        std::io::copy(&mut entry, &mut w).map_err(e2s)?;
        #[cfg(unix)]
        if let Some(mode) = entry.unix_mode() {
            use std::os::unix::fs::PermissionsExt;
            let _ = fs::set_permissions(&out, fs::Permissions::from_mode(mode));
        }
    }
    // Archive avec un seul dossier racine : on le « remonte »
    let items: Vec<_> = fs::read_dir(dest).map_err(e2s)?.flatten().collect();
    if items.len() == 1 && items[0].path().is_dir() {
        let inner = items[0].path();
        for e in fs::read_dir(&inner).map_err(e2s)?.flatten() {
            fs::rename(e.path(), dest.join(e.file_name())).map_err(e2s)?;
        }
        let _ = fs::remove_dir(&inner);
    }
    Ok(())
}

/// Cherche quoi lancer quand le catalogue ne le précise pas
fn find_launchable(root: &Path) -> Option<PathBuf> {
    let mut found = Vec::new();
    for e in fs::read_dir(root).ok()?.flatten() {
        let p = e.path();
        let name = p.file_name()?.to_string_lossy().to_lowercase();
        let ok = if cfg!(windows) {
            name.ends_with(".exe") && !name.contains("unins") && !name.contains("setup")
        } else if cfg!(target_os = "macos") {
            name.ends_with(".app")
        } else {
            name.ends_with(".appimage") || (p.is_file() && is_executable(&p) && !name.contains('.'))
        };
        if ok {
            found.push(p);
        }
    }
    found.sort();
    found.into_iter().next()
}

#[cfg(unix)]
fn is_executable(p: &Path) -> bool {
    use std::os::unix::fs::PermissionsExt;
    fs::metadata(p).map(|m| m.permissions().mode() & 0o111 != 0).unwrap_or(false)
}
#[cfg(not(unix))]
fn is_executable(_: &Path) -> bool {
    false
}

fn make_executable(_p: &Path) {
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        if let Ok(m) = fs::metadata(_p) {
            if m.is_file() {
                let mut perm = m.permissions();
                perm.set_mode(perm.mode() | 0o755);
                let _ = fs::set_permissions(_p, perm);
            }
        }
    }
}

fn open_with_system(p: &Path) -> R<()> {
    #[cfg(windows)]
    let st = Command::new("explorer").arg(p).spawn();
    #[cfg(target_os = "macos")]
    let st = Command::new("open").arg(p).spawn();
    #[cfg(all(unix, not(target_os = "macos")))]
    let st = Command::new("xdg-open").arg(p).spawn();
    st.map(|_| ()).map_err(|e| format!("Impossible d'ouvrir le fichier : {e}"))
}

#[tauri::command]
pub fn launch_app<Rt: Runtime>(app: AppHandle<Rt>, slug: String) -> R<()> {
    check_slug(&slug)?;
    let reg = load_registry(&app);
    let entry = reg.get(&slug).ok_or("Cette appli n'est pas installée")?;
    let target = entry
        .launch
        .as_deref()
        .ok_or("Le catalogue n'indique pas quoi lancer pour cette appli : ajoute un « chemin de lancement » dans le panel")?;
    let path = PathBuf::from(target);
    if !path.exists() {
        return Err("Programme introuvable : il a peut-être été désinstallé. Réinstalle-le depuis le launcher.".into());
    }
    let cwd = path.parent().map(Path::to_path_buf).unwrap_or_else(std::env::temp_dir);

    #[cfg(target_os = "macos")]
    let res = if target.ends_with(".app") {
        Command::new("open").arg("-a").arg(&path).spawn()
    } else {
        Command::new(&path).current_dir(&cwd).spawn()
    };
    #[cfg(windows)]
    let res = {
        use std::os::windows::process::CommandExt;
        const DETACHED_PROCESS: u32 = 0x0000_0008;
        Command::new(&path).current_dir(&cwd).creation_flags(DETACHED_PROCESS).spawn()
    };
    #[cfg(all(unix, not(target_os = "macos")))]
    let res = Command::new(&path).current_dir(&cwd).spawn();

    res.map(|_| ()).map_err(|e| format!("Impossible de lancer l'appli : {e}"))
}

/// Désinstalle une appli portable / AppImage, ou l'oublie simplement (installeurs du système)
#[tauri::command]
pub fn remove_app<Rt: Runtime>(app: AppHandle<Rt>, state: State<'_, AppsState>, slug: String) -> R<bool> {
    check_slug(&slug)?;
    let _guard = state.registry.lock().map_err(e2s)?;
    let mut reg = load_registry(&app);
    let Some(entry) = reg.remove(&slug) else { return Ok(false) };
    let mut deleted = false;
    if let Some(dir) = entry.dir.as_deref() {
        let dir = PathBuf::from(dir);
        // Sécurité : on ne supprime que dans le dossier apps/ du launcher
        if dir.starts_with(apps_root(&app)?) && dir.exists() {
            fs::remove_dir_all(&dir).map_err(|e| format!("Suppression impossible (appli encore ouverte ?) : {e}"))?;
            deleted = true;
        }
    }
    save_registry(&app, &reg)?;
    Ok(deleted)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn args_split() {
        assert_eq!(split_args(r#"/S /D="C:\Program Files\X""#), vec!["/S", r"/D=C:\Program Files\X"]);
        assert!(split_args("  ").is_empty());
    }

    #[test]
    fn vars() {
        std::env::set_var("NL_TEST_DIR", "/opt/x");
        assert_eq!(expand_vars("%NL_TEST_DIR%/app"), "/opt/x/app");
        assert_eq!(expand_vars("$NL_TEST_DIR/app"), "/opt/x/app");
        assert_eq!(expand_vars("${NL_TEST_DIR}/app"), "/opt/x/app");
        assert_eq!(expand_vars("%INCONNUE_NL%/a"), "%INCONNUE_NL%/a");
        assert_eq!(expand_vars("50%"), "50%");
    }

    #[test]
    fn slugs() {
        assert!(check_slug("nounours-cine").is_ok());
        assert!(check_slug("../etc").is_err());
        assert!(check_slug("").is_err());
    }

    /// Bout en bout : téléchargement depuis un serveur local, vérification, installation, lancement, désinstallation
    #[cfg(unix)]
    #[test]
    fn install_launch_remove() {
        use std::io::{Read, Write as _};
        use std::net::TcpListener;

        // Petit serveur HTTP qui sert les fichiers de test
        let mut zipbuf = Vec::new();
        {
            let mut z = zip::ZipWriter::new(std::io::Cursor::new(&mut zipbuf));
            let opt = zip::write::SimpleFileOptions::default().unix_permissions(0o755);
            z.add_directory("MonAppli-1.0/", opt).unwrap();
            z.start_file("MonAppli-1.0/monappli", opt).unwrap();
            z.write_all(b"#!/bin/sh\necho lance > \"$NL_TEST_OUT\"\n").unwrap();
            z.start_file("MonAppli-1.0/lisezmoi.txt", opt).unwrap();
            z.write_all(b"doc").unwrap();
            z.finish().unwrap();
        }
        let appimage = b"#!/bin/sh\necho appimage > \"$NL_TEST_OUT\"\n".to_vec();
        let files: Vec<(&'static str, Vec<u8>)> = vec![("/app.zip", zipbuf.clone()), ("/app.AppImage", appimage.clone())];
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        std::thread::spawn(move || {
            for stream in listener.incoming().flatten() {
                let mut stream = stream;
                let mut buf = [0u8; 2048];
                let n = stream.read(&mut buf).unwrap_or(0);
                let req = String::from_utf8_lossy(&buf[..n]);
                let path = req.split_whitespace().nth(1).unwrap_or("/").to_string();
                let body = files.iter().find(|(p, _)| *p == path).map(|(_, b)| b.clone());
                let resp = match body {
                    Some(b) => { let mut r = format!("HTTP/1.1 200 OK\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", b.len()).into_bytes(); r.extend(b); r }
                    None => b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".to_vec(),
                };
                let _ = stream.write_all(&resp);
            }
        });

        let app = tauri::test::mock_app();
        app.manage(AppsState::default());
        let h = app.handle().clone();
        let out = std::env::temp_dir().join(format!("nl-test-out-{}", std::process::id()));
        std::env::set_var("NL_TEST_OUT", &out);
        let sha = |b: &[u8]| hex::encode(Sha256::digest(b));
        let req = |slug: &str, kind: &str, file: &str, sum: Option<String>| InstallRequest {
            slug: slug.into(), version: "1.0.0".into(), url: format!("http://127.0.0.1:{port}{file}"),
            sha256: sum, kind: kind.into(), install_args: None, launch_path: None,
        };

        // 1) Empreinte fausse → refus
        let bad = tauri::async_runtime::block_on(install_app(h.clone(), app.state(), req("nl-test-zip", "zip", "/app.zip", Some("00".repeat(32)))));
        assert!(bad.unwrap_err().contains("SHA-256"));

        // 2) Zip avec dossier racine : extrait, « remonté », exécutable trouvé tout seul
        let v = tauri::async_runtime::block_on(install_app(h.clone(), app.state(), req("nl-test-zip", "zip", "/app.zip", Some(sha(&zipbuf))))).unwrap();
        assert!(v.exists && v.removable);
        assert!(v.app.launch.as_deref().unwrap().ends_with("nl-test-zip/monappli"), "{:?}", v.app.launch);
        let _ = fs::remove_file(&out);
        launch_app(h.clone(), "nl-test-zip".into()).unwrap();
        std::thread::sleep(std::time::Duration::from_millis(500));
        assert_eq!(fs::read_to_string(&out).unwrap().trim(), "lance");

        // 3) AppImage
        let v = tauri::async_runtime::block_on(install_app(h.clone(), app.state(), req("nl-test-ai", "appimage", "/app.AppImage", Some(sha(&appimage))))).unwrap();
        assert!(v.app.launch.as_deref().unwrap().ends_with("nl-test-ai.AppImage"));
        launch_app(h.clone(), "nl-test-ai".into()).unwrap();
        std::thread::sleep(std::time::Duration::from_millis(500));
        assert_eq!(fs::read_to_string(&out).unwrap().trim(), "appimage");

        // 4) Registre, puis désinstallation
        let list = installed_apps(h.clone());
        assert_eq!(list.get("nl-test-zip").unwrap().app.version, "1.0.0");
        let dir = PathBuf::from(list.get("nl-test-zip").unwrap().app.dir.clone().unwrap());
        assert!(remove_app(h.clone(), app.state(), "nl-test-zip".into()).unwrap());
        assert!(!dir.exists());
        assert!(remove_app(h.clone(), app.state(), "nl-test-ai".into()).unwrap());
        assert!(installed_apps(h.clone()).get("nl-test-zip").is_none());
        let _ = fs::remove_file(&out);
    }
}
