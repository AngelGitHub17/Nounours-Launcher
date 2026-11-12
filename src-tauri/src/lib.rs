//! NounoursLauncher — cœur natif.
//! L'interface (HTML/JS) fait presque tout ; ici on branche les plugins
//! et on expose quelques informations sur la machine.

mod apps;

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

    builder
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_process::init())
        .manage(apps::AppsState::default())
        .invoke_handler(tauri::generate_handler![
            platform_key,
            apps::installed_apps,
            apps::apps_folder,
            apps::install_app,
            apps::launch_app,
            apps::remove_app,
        ])
        .run(tauri::generate_context!())
        .expect("erreur au démarrage de NounoursLauncher");
}
