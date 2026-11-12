//! NounoursLauncher — cœur natif.
//! L'interface (HTML/JS) fait presque tout ; ici on branche les plugins
//! et on expose quelques informations sur la machine.

#[cfg(desktop)]
mod apps;
#[cfg(desktop)]
mod detect;

#[cfg(desktop)]
use tauri::Manager;

/// Clé de plateforme au format du catalogue : windows-x86_64, darwin-aarch64, linux-x86_64, android, ios…
#[tauri::command]
fn platform_key() -> String {
    let os = match std::env::consts::OS {
        "windows" => "windows",
        "macos" => "darwin",
        "linux" => "linux",
        "android" => return "android".into(),
        "ios" => return "ios".into(),
        other => other,
    };
    let arch = match std::env::consts::ARCH {
        "aarch64" => "aarch64",
        _ => "x86_64",
    };
    format!("{os}-{arch}")
}

/// Panel d'administration du catalogue dans une fenêtre du launcher (comptes admin NounoursAccount).
/// La page est distante : elle n'a aucun accès aux commandes du launcher.
#[cfg(desktop)]
#[tauri::command]
async fn open_admin_window(app: tauri::AppHandle, url: String) -> Result<(), String> {
    let parsed: tauri::Url = url.parse().map_err(|_| "Adresse invalide".to_string())?;
    let local = matches!(parsed.host_str(), Some("localhost") | Some("127.0.0.1"));
    if parsed.scheme() != "https" && !local {
        return Err("Le panel doit être en https".into());
    }
    if let Some(w) = app.get_webview_window("admin") {
        w.navigate(parsed).map_err(|e| e.to_string())?;
        let _ = w.unminimize();
        let _ = w.show();
        return w.set_focus().map_err(|e| e.to_string());
    }
    tauri::WebviewWindowBuilder::new(&app, "admin", tauri::WebviewUrl::External(parsed))
        .title("NounoursLauncher — Administration")
        .inner_size(1280.0, 860.0)
        .min_inner_size(720.0, 500.0)
        .build()
        .map(|_| ())
        .map_err(|e| e.to_string())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default();

    #[cfg(desktop)]
    {
        // Doit être le premier plugin : un 2e lancement remet simplement la fenêtre au premier plan
        builder = builder
            .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
                if let Some(w) = app.get_webview_window("main") {
                    let _ = w.unminimize();
                    let _ = w.show();
                    let _ = w.set_focus();
                }
            }))
            .plugin(tauri_plugin_updater::Builder::new().build())
            // Lancement au démarrage de l'ordinateur (activable dans les Réglages) : démarre réduit
            .plugin(tauri_plugin_autostart::init(
                tauri_plugin_autostart::MacosLauncher::LaunchAgent,
                Some(vec!["--minimized"]),
            ))
            // Retient la taille et la position de la fenêtre d'une fois sur l'autre
            .plugin(tauri_plugin_window_state::Builder::default().build())
            .setup(|app| {
                if std::env::args().any(|a| a == "--minimized") {
                    if let Some(w) = app.get_webview_window("main") {
                        let _ = w.minimize();
                    }
                }
                Ok(())
            });
    }

    // Ordinateur : installation et lancement des applis. Mobile : le système s'en charge.
    #[cfg(desktop)]
    let builder = builder.manage(apps::AppsState::default()).invoke_handler(tauri::generate_handler![
        platform_key,
        apps::installed_apps,
        apps::apps_folder,
        apps::install_app,
        apps::launch_app,
        apps::remove_app,
        open_admin_window,
    ]);
    #[cfg(mobile)]
    let builder = builder.invoke_handler(tauri::generate_handler![platform_key]);

    builder
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_process::init())
        .run(tauri::generate_context!())
        .expect("erreur au démarrage de NounoursLauncher");
}
