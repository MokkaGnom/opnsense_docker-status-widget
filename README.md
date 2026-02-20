# OPNsense Docker Status Widget

This widget shows Docker container status from multiple servers using the backend in the `backend/` folder.

## Install (OPNsense 25.x MVC widgets)

Copy these files to your OPNsense system:

- /usr/local/opnsense/mvc/app/controllers/OPNsense/DockerStatus/Api/StatusController.php
- /usr/local/opnsense/mvc/app/models/OPNsense/DockerStatus/ACL/ACL.xml
- /usr/local/opnsense/www/js/widgets/DockerStatus.js
- /usr/local/opnsense/www/js/widgets/Metadata/DockerStatus.xml

Then open Dashboard -> Add Widget and select "Docker Status".

If your user is not admin, make sure it has the new ACL entry
"WebCfg - Services: Docker Status widget".

## Configure

- Open the widget settings.
- Add one server per line using one of these formats:
  - `MyServer|192.168.1.10`
  - `192.168.1.10`
- Optional: provide a full URL (http/https). If no scheme is provided, the widget uses `http://<host>:42679/`.
- Set the refresh interval in seconds.

## Notes

- The widget proxies requests through OPNsense, so browser CORS does not apply.
- The backend must be reachable from OPNsense at `http://<host>:42679/` or the custom URL you provide.
- After changing settings inside the widget, click "Save" on the dashboard to persist.
