//! Trouver où une appli s'est installée, pour pouvoir la lancer.
//!
//! Deux méthodes, de la plus sûre à la plus large :
//! 1. Avant / après : on note ce qui est installé avant de lancer l'installeur, puis on regarde
//!    ce qui est apparu (ou a changé) après. Windows : entrées « Programmes et fonctionnalités »
//!    du registre. macOS : applis de /Applications et ~/Applications. Linux : raccourcis .desktop.
//! 2. Par le nom : si l'installeur s'est fait sans le launcher ou en arrière-plan (paquet .deb,
//!    .pkg ouvert à part…), on cherche une appli dont le nom ressemble à celui du projet.
//!
//! Ce qui est trouvé peut être un exécutable, un .app (macOS), un raccourci .lnk (Windows)
//! ou un fichier .desktop (Linux) : `launch_target` sait lancer chacun.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
#[cfg(target_os = "macos")]
use std::time::SystemTime;

/// « Nounours Ciné Manager », « nounours-cine-manager » et « NounoursCineManager » → « nounourscinemanager »
pub fn norm(s: &str) -> String {
    s.chars()
        .flat_map(|c| c.to_lowercase())
        .map(|c| match c {
            'à' | 'â' | 'ä' => 'a',
            'é' | 'è' | 'ê' | 'ë' => 'e',
            'î' | 'ï' => 'i',
            'ô' | 'ö' => 'o',
            'ù' | 'û' | 'ü' => 'u',
            'ç' => 'c',
            c => c,
        })
        .filter(|c| c.is_ascii_alphanumeric())
        .collect()
}

/// Le nom d'une appli trouvée correspond-il au projet ? (score : 0 = non, plus haut = mieux)
pub fn name_score(candidate: &str, wanted: &[String]) -> u32 {
    let c = norm(candidate);
    if c.len() < 3 {
        return 0;
    }
    let mut best = 0;
    for w in wanted {
        if w.len() < 3 {
            continue;
        }
        let s = if &c == w {
            100
        } else if c.starts_with(w.as_str()) || w.starts_with(c.as_str()) {
            80
        } else if c.contains(w.as_str()) || w.contains(c.as_str()) {
            60
        } else {
            0
        };
        best = best.max(s);
    }
    best
}

/// Noms à chercher pour un projet : son nom et son identifiant
pub fn wanted_names(name: Option<&str>, slug: &str) -> Vec<String> {
    let mut v = vec![norm(slug)];
    if let Some(n) = name {
        v.push(norm(n));
    }
    v.retain(|s| s.len() >= 3);
    v.dedup();
    v
}

fn is_helper_exe(name: &str) -> bool {
    let n = name.to_lowercase();
    ["unins", "uninstall", "setup", "install", "update", "crashpad", "helper", "elevate", "vc_redist", "dotnet", "node.exe", "createdump"]
        .iter()
        .any(|k| n.contains(k))
}

/// Meilleur exécutable dans un dossier (2 niveaux), en préférant celui qui porte le nom du projet
pub fn best_exe_in(dir: &Path, wanted: &[String]) -> Option<PathBuf> {
    let mut found: Vec<(u32, PathBuf)> = Vec::new();
    let mut walk = vec![(dir.to_path_buf(), 0)];
    while let Some((d, depth)) = walk.pop() {
        let Ok(rd) = std::fs::read_dir(&d) else { continue };
        for e in rd.flatten() {
            let p = e.path();
            if p.is_dir() {
                if depth < 1 {
                    walk.push((p, depth + 1));
                }
                continue;
            }
            let fname = p.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
            if !fname.to_lowercase().ends_with(".exe") || is_helper_exe(&fname) {
                continue;
            }
            let stem = fname.trim_end_matches(".exe").trim_end_matches(".EXE");
            // un exécutable à la racine passe avant un sous-dossier, le nom du projet avant tout
            let score = name_score(stem, wanted) * 10 + if depth == 0 { 5 } else { 0 };
            found.push((score, p));
        }
    }
    found.sort_by(|a, b| b.0.cmp(&a.0));
    found.into_iter().next().map(|(_, p)| p)
}

// =====================================================================
// Windows : « Programmes et fonctionnalités » (clés Uninstall du registre)
// =====================================================================

#[derive(Clone, Debug, PartialEq, Default)]
pub struct Entry {
    pub name: String,
    pub version: String,
    pub icon: String,
    pub location: String,
    pub stamp: u64,
}

pub type Snapshot = BTreeMap<String, Entry>;

#[cfg(windows)]
pub fn snapshot() -> Snapshot {
    use winreg::enums::*;
    use winreg::RegKey;
    let mut out = Snapshot::new();
    let roots = [
        (HKEY_CURRENT_USER, "HKCU", r"Software\Microsoft\Windows\CurrentVersion\Uninstall"),
        (HKEY_LOCAL_MACHINE, "HKLM", r"Software\Microsoft\Windows\CurrentVersion\Uninstall"),
        (HKEY_LOCAL_MACHINE, "HKLM32", r"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"),
    ];
    for (hive, label, path) in roots {
        let Ok(key) = RegKey::predef(hive).open_subkey_with_flags(path, KEY_READ) else { continue };
        for sub in key.enum_keys().flatten() {
            let Ok(k) = key.open_subkey_with_flags(&sub, KEY_READ) else { continue };
            let name: String = k.get_value("DisplayName").unwrap_or_default();
            if name.is_empty() {
                continue;
            }
            out.insert(
                format!("{label}\\{sub}"),
                Entry {
                    name,
                    version: k.get_value("DisplayVersion").unwrap_or_default(),
                    icon: k.get_value("DisplayIcon").unwrap_or_default(),
                    location: k.get_value("InstallLocation").unwrap_or_default(),
                    stamp: 0, // une mise à jour se voit au changement de DisplayVersion
                },
            );
        }
    }
    out
}

/// Exécutable d'une entrée du registre : DisplayIcon (souvent l'exe lui-même), sinon le dossier d'installation
#[cfg_attr(not(windows), allow(dead_code))]
fn exe_of_entry(e: &Entry, wanted: &[String]) -> Option<PathBuf> {
    let icon = e.icon.trim().trim_matches('"');
    let icon = icon.rsplit_once(',').map(|(p, n)| if n.trim().parse::<i32>().is_ok() { p } else { icon }).unwrap_or(icon);
    let icon = icon.trim().trim_matches('"');
    let ip = PathBuf::from(icon);
    if icon.to_lowercase().ends_with(".exe") && ip.exists() && !is_helper_exe(&ip.file_name()?.to_string_lossy()) {
        return Some(ip);
    }
    let loc = e.location.trim().trim_matches('"');
    if !loc.is_empty() {
        if let Some(p) = best_exe_in(Path::new(loc), wanted) {
            return Some(p);
        }
    }
    if ip.exists() {
        if let Some(dir) = ip.parent() {
            return best_exe_in(dir, wanted);
        }
    }
    None
}

/// Après un installeur : l'entrée apparue ou modifiée qui ressemble le plus au projet
#[cfg(windows)]
pub fn detect_after(before: &Snapshot, wanted: &[String]) -> Option<PathBuf> {
    let after = snapshot();
    let mut cands: Vec<(u32, &Entry)> = after
        .iter()
        .filter(|(k, e)| before.get(*k) != Some(*e))
        .map(|(_, e)| (name_score(&e.name, wanted) + 10, e)) // +10 : nouvelle entrée, même sans le bon nom
        .collect();
    cands.sort_by(|a, b| b.0.cmp(&a.0));
    // Une seule nouveauté : c'est forcément elle. Plusieurs : il faut que le nom corresponde.
    let only_one = cands.len() == 1;
    cands
        .into_iter()
        .filter(|(s, _)| only_one || *s > 10)
        .find_map(|(_, e)| exe_of_entry(e, wanted))
        .or_else(|| search_by_name(wanted))
}

// =====================================================================
// macOS : applis de /Applications et ~/Applications
// =====================================================================

#[cfg(target_os = "macos")]
fn app_dirs() -> Vec<PathBuf> {
    let mut v = vec![PathBuf::from("/Applications")];
    if let Ok(h) = std::env::var("HOME") {
        v.push(PathBuf::from(h).join("Applications"));
    }
    v
}

#[cfg(target_os = "macos")]
pub fn snapshot() -> Snapshot {
    let mut out = Snapshot::new();
    for d in app_dirs() {
        let Ok(rd) = std::fs::read_dir(&d) else { continue };
        for e in rd.flatten() {
            let p = e.path();
            if p.extension().map(|x| x == "app").unwrap_or(false) {
                let stamp = e.metadata().ok().and_then(|m| m.modified().ok()).and_then(|t| t.duration_since(SystemTime::UNIX_EPOCH).ok()).map(|d| d.as_secs()).unwrap_or(0);
                let name = p.file_stem().map(|s| s.to_string_lossy().into_owned()).unwrap_or_default();
                out.insert(p.to_string_lossy().into_owned(), Entry { name, stamp, ..Default::default() });
            }
        }
    }
    out
}

#[cfg(target_os = "macos")]
pub fn detect_after(before: &Snapshot, wanted: &[String]) -> Option<PathBuf> {
    let after = snapshot();
    let mut cands: Vec<(u32, &String)> = after
        .iter()
        .filter(|(k, e)| before.get(*k).map(|b| b.stamp != e.stamp).unwrap_or(true))
        .map(|(k, e)| (name_score(&e.name, wanted) + 10, k))
        .collect();
    cands.sort_by(|a, b| b.0.cmp(&a.0));
    let only_one = cands.len() == 1;
    cands
        .into_iter()
        .find(|(s, _)| only_one || *s > 10)
        .map(|(_, k)| PathBuf::from(k))
        .or_else(|| search_by_name(wanted))
}

// =====================================================================
// Linux : raccourcis .desktop (menus d'applications)
// =====================================================================

#[cfg(all(unix, not(target_os = "macos")))]
fn desktop_dirs() -> Vec<PathBuf> {
    let mut v = vec![PathBuf::from("/usr/share/applications"), PathBuf::from("/usr/local/share/applications"), PathBuf::from("/var/lib/flatpak/exports/share/applications")];
    if let Ok(h) = std::env::var("HOME") {
        v.push(PathBuf::from(&h).join(".local/share/applications"));
        v.push(PathBuf::from(&h).join(".local/share/flatpak/exports/share/applications"));
    }
    v
}

#[cfg(all(unix, not(target_os = "macos")))]
fn desktop_name(p: &Path) -> Option<String> {
    let text = std::fs::read_to_string(p).ok()?;
    let mut in_entry = false;
    for line in text.lines() {
        let l = line.trim();
        if l.starts_with('[') {
            in_entry = l == "[Desktop Entry]";
            continue;
        }
        if in_entry {
            if l == "NoDisplay=true" || l == "Hidden=true" {
                return None;
            }
            if let Some(n) = l.strip_prefix("Name=") {
                return Some(n.to_string());
            }
        }
    }
    None
}

#[cfg(all(unix, not(target_os = "macos")))]
pub fn snapshot() -> Snapshot {
    let mut out = Snapshot::new();
    for d in desktop_dirs() {
        let Ok(rd) = std::fs::read_dir(&d) else { continue };
        for e in rd.flatten() {
            let p = e.path();
            if p.extension().map(|x| x == "desktop").unwrap_or(false) {
                if let Some(name) = desktop_name(&p) {
                    out.insert(p.to_string_lossy().into_owned(), Entry { name, ..Default::default() });
                }
            }
        }
    }
    out
}

#[cfg(all(unix, not(target_os = "macos")))]
pub fn detect_after(before: &Snapshot, wanted: &[String]) -> Option<PathBuf> {
    let after = snapshot();
    after
        .iter()
        .filter(|(k, _)| !before.contains_key(*k))
        .map(|(k, e)| (name_score(&e.name, wanted), k))
        .filter(|(s, _)| *s > 0)
        .max_by_key(|(s, _)| *s)
        .map(|(_, k)| PathBuf::from(k))
        .or_else(|| search_by_name(wanted))
}

// =====================================================================
// Recherche par le nom (toutes plateformes)
// =====================================================================

pub fn search_by_name(wanted: &[String]) -> Option<PathBuf> {
    if wanted.is_empty() {
        return None;
    }
    // Registre / applis / .desktop dont le nom correspond
    let snap = snapshot();
    let mut best: Vec<(u32, &String, &Entry)> = snap.iter().map(|(k, e)| (name_score(&e.name, wanted), k, e)).filter(|(s, _, _)| *s >= 60).collect();
    best.sort_by(|a, b| b.0.cmp(&a.0));

    #[cfg(windows)]
    {
        if let Some(p) = best.iter().find_map(|(_, _, e)| exe_of_entry(e, wanted)) {
            return Some(p);
        }
        // Raccourcis du menu Démarrer (installeurs qui ne s'inscrivent pas dans le registre)
        let mut roots = Vec::new();
        if let Ok(a) = std::env::var("APPDATA") {
            roots.push(PathBuf::from(a).join(r"Microsoft\Windows\Start Menu\Programs"));
        }
        if let Ok(a) = std::env::var("ProgramData") {
            roots.push(PathBuf::from(a).join(r"Microsoft\Windows\Start Menu\Programs"));
        }
        let mut lnks: Vec<(u32, PathBuf)> = Vec::new();
        let mut walk: Vec<(PathBuf, u32)> = roots.into_iter().map(|r| (r, 0)).collect();
        while let Some((d, depth)) = walk.pop() {
            let Ok(rd) = std::fs::read_dir(&d) else { continue };
            for e in rd.flatten() {
                let p = e.path();
                if p.is_dir() && depth < 2 {
                    walk.push((p, depth + 1));
                } else if p.extension().map(|x| x.eq_ignore_ascii_case("lnk")).unwrap_or(false) {
                    let stem = p.file_stem().map(|s| s.to_string_lossy().into_owned()).unwrap_or_default();
                    if is_helper_exe(&stem) {
                        continue;
                    }
                    let s = name_score(&stem, wanted);
                    if s >= 60 {
                        lnks.push((s, p));
                    }
                }
            }
        }
        lnks.sort_by(|a, b| b.0.cmp(&a.0));
        return lnks.into_iter().next().map(|(_, p)| p);
    }
    #[cfg(not(windows))]
    {
        best.into_iter().next().map(|(_, k, _)| PathBuf::from(k))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names() {
        let w = wanted_names(Some("Nounours Ciné Manager"), "nounours-cine-manager");
        assert_eq!(w, vec!["nounourscinemanager"]);
        assert_eq!(name_score("NounoursCineManager", &w), 100);
        assert_eq!(name_score("NounoursCineManager 1.4", &w), 80);
        assert!(name_score("Microsoft Edge", &w) == 0);
        let rz = wanted_names(Some("RzParty"), "rzparty");
        assert_eq!(name_score("RzParty Serveur", &rz), 80);
        assert_eq!(name_score("Mon RzParty", &rz), 60);
    }

    #[test]
    fn exe_in_dir() {
        let d = std::env::temp_dir().join(format!("nl-exe-{}", std::process::id()));
        std::fs::create_dir_all(d.join("bin")).unwrap();
        for f in ["unins000.exe", "RzParty.exe", "bin/node.exe", "Helper.exe"] {
            std::fs::write(d.join(f), b"x").unwrap();
        }
        let w = wanted_names(Some("RzParty"), "rzparty");
        assert!(best_exe_in(&d, &w).unwrap().ends_with("RzParty.exe"));
        // Registre : DisplayIcon « "C:\…\RzParty.exe",0 »
        let e = Entry { icon: format!("\"{}\",0", d.join("RzParty.exe").display()), ..Default::default() };
        assert!(exe_of_entry(&e, &w).unwrap().ends_with("RzParty.exe"));
        // Pas d'icône utilisable : on cherche dans le dossier d'installation
        let e = Entry { icon: format!("{}", d.join("unins000.exe").display()), location: d.display().to_string(), ..Default::default() };
        assert!(exe_of_entry(&e, &w).unwrap().ends_with("RzParty.exe"));
        let _ = std::fs::remove_dir_all(&d);
    }

    #[cfg(all(unix, not(target_os = "macos")))]
    #[test]
    fn desktop_detection() {
        let home = std::env::temp_dir().join(format!("nl-home-{}", std::process::id()));
        let apps = home.join(".local/share/applications");
        std::fs::create_dir_all(&apps).unwrap();
        let old_home = std::env::var_os("HOME");
        std::env::set_var("HOME", &home);
        let before = snapshot();
        std::fs::write(apps.join("rzparty.desktop"), "[Desktop Entry]\nType=Application\nName=RzParty\nExec=/opt/rzparty/rzparty %U\n").unwrap();
        std::fs::write(apps.join("autre.desktop"), "[Desktop Entry]\nName=Autre chose\nExec=x\n").unwrap();
        let w = wanted_names(Some("RzParty"), "rzparty");
        assert!(detect_after(&before, &w).unwrap().ends_with("rzparty.desktop"));
        assert!(search_by_name(&w).unwrap().ends_with("rzparty.desktop"));
        if let Some(h) = old_home { std::env::set_var("HOME", h); }
        let _ = std::fs::remove_dir_all(&home);
    }
}
