<?php

require_once("guiconfig.inc");

$widget_name = "docker_status";

if (!isset($config["widgets"])) {
    $config["widgets"] = [];
}
if (!isset($config["widgets"][$widget_name])) {
    $config["widgets"][$widget_name] = [];
}

$widget_config = $config["widgets"][$widget_name];
$servers_config = isset($widget_config["servers"]) && is_array($widget_config["servers"]) ? $widget_config["servers"] : [];
$refresh_seconds = isset($widget_config["refresh"]) ? intval($widget_config["refresh"]) : 30;

function ds_parse_servers_text($text)
{
    $servers = [];
    $lines = preg_split("/\r\n|\r|\n/", trim($text));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || strpos($line, "#") === 0) {
            continue;
        }
        $name = "";
        $host = "";
        if (strpos($line, "|") !== false) {
            list($name, $host) = array_map("trim", explode("|", $line, 2));
        } elseif (strpos($line, ",") !== false) {
            list($name, $host) = array_map("trim", explode(",", $line, 2));
        } else {
            $name = $line;
            $host = $line;
        }
        if ($host !== "") {
            $servers[] = ["name" => $name === "" ? $host : $name, "host" => $host];
        }
    }
    return $servers;
}

function ds_build_url($host)
{
    if (preg_match("~^https?://~i", $host)) {
        return rtrim($host, "/") . "/";
    }
    return "http://" . rtrim($host, "/") . ":42679/";
}

function ds_fetch_json($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json"]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ["ok" => false, "error" => $error !== "" ? $error : "request failed"];
    }

    if ($status !== 200) {
        return ["ok" => false, "error" => "http " . $status];
    }

    $data = json_decode($body, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ["ok" => false, "error" => "invalid json"];
    }

    return ["ok" => true, "data" => $data];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save") {
    $servers_text = isset($_POST["servers"]) ? $_POST["servers"] : "";
    $refresh = isset($_POST["refresh"]) ? intval($_POST["refresh"]) : 30;
    if ($refresh < 5) {
        $refresh = 5;
    }

    $servers = ds_parse_servers_text($servers_text);

    $config["widgets"][$widget_name]["servers"] = $servers;
    $config["widgets"][$widget_name]["refresh"] = $refresh;
    write_config("Updated Docker Status widget settings");

    header("Content-Type: application/json");
    echo json_encode(["ok" => true, "servers" => $servers, "refresh" => $refresh]);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "fetch") {
    $index = isset($_GET["server"]) ? intval($_GET["server"]) : -1;
    if ($index < 0 || $index >= count($servers_config)) {
        header("Content-Type: application/json");
        echo json_encode(["ok" => false, "error" => "unknown server"]);
        exit;
    }

    $host = $servers_config[$index]["host"];
    $url = ds_build_url($host);
    $result = ds_fetch_json($url);

    header("Content-Type: application/json");
    if ($result["ok"]) {
        echo json_encode(["ok" => true, "data" => $result["data"]]);
    } else {
        echo json_encode(["ok" => false, "error" => $result["error"]]);
    }
    exit;
}

$servers_text_default = "";
foreach ($servers_config as $server) {
    $servers_text_default .= $server["name"] . "|" . $server["host"] . "\n";
}

$endpoint = "/widgets/widgets/docker_status.widget.php";
?>

<div class="docker-status-widget" data-endpoint="<?= htmlspecialchars($endpoint); ?>" data-refresh="<?= htmlspecialchars($refresh_seconds); ?>">
    <style>
        .docker-status-widget .ds-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .docker-status-widget .ds-settings { margin-bottom: 8px; }
        .docker-status-widget .ds-settings textarea { width: 100%; min-height: 80px; }
        .docker-status-widget .ds-server { border: 1px solid #d8d8d8; padding: 6px; margin-bottom: 8px; border-radius: 4px; }
        .docker-status-widget .ds-server h5 { margin: 0 0 6px 0; font-weight: 600; }
        .docker-status-widget table { width: 100%; border-collapse: collapse; }
        .docker-status-widget th, .docker-status-widget td { padding: 3px 4px; border-bottom: 1px solid #efefef; font-size: 12px; }
        .docker-status-widget .ds-status-running { color: #2c7a2c; font-weight: 600; }
        .docker-status-widget .ds-status-exited { color: #a03c3c; font-weight: 600; }
        .docker-status-widget .ds-status-paused { color: #a0702c; font-weight: 600; }
        .docker-status-widget .ds-health-healthy { color: #2c7a2c; font-weight: 600; }
        .docker-status-widget .ds-health-unhealthy { color: #a03c3c; font-weight: 600; }
        .docker-status-widget .ds-muted { color: #777; font-size: 12px; }
    </style>

    <div class="ds-toolbar">
        <div class="ds-muted">Docker status</div>
        <button type="button" class="btn btn-xs btn-default ds-toggle">Settings</button>
    </div>

    <div class="ds-settings" style="display: none;">
        <label>Servers (one per line, format: name|host or host)</label>
        <textarea class="form-control ds-servers"><?= htmlspecialchars($servers_text_default); ?></textarea>
        <label style="margin-top: 6px;">Refresh (seconds)</label>
        <input type="number" class="form-control ds-refresh" min="5" value="<?= htmlspecialchars($refresh_seconds); ?>">
        <button type="button" class="btn btn-xs btn-primary ds-save" style="margin-top: 6px;">Save</button>
        <span class="ds-save-status ds-muted" style="margin-left: 6px;"></span>
    </div>

    <div class="ds-content ds-muted">No servers configured.</div>
</div>

<script>
(function() {
    var widget = document.currentScript.parentElement;
    if (!widget || !widget.classList.contains("docker-status-widget")) {
        return;
    }

    var endpoint = widget.getAttribute("data-endpoint");
    var refreshSeconds = parseInt(widget.getAttribute("data-refresh"), 10) || 30;
    var content = widget.querySelector(".ds-content");
    var toggle = widget.querySelector(".ds-toggle");
    var settings = widget.querySelector(".ds-settings");
    var saveButton = widget.querySelector(".ds-save");
    var serversInput = widget.querySelector(".ds-servers");
    var refreshInput = widget.querySelector(".ds-refresh");
    var saveStatus = widget.querySelector(".ds-save-status");

    function parseServers(text) {
        return text.split(/\r\n|\r|\n/).map(function(line) {
            line = line.trim();
            if (!line || line.indexOf("#") === 0) {
                return null;
            }
            var name = "";
            var host = "";
            if (line.indexOf("|") !== -1) {
                var parts = line.split("|");
                name = parts[0].trim();
                host = parts.slice(1).join("|").trim();
            } else if (line.indexOf(",") !== -1) {
                var partsComma = line.split(",");
                name = partsComma[0].trim();
                host = partsComma.slice(1).join(",").trim();
            } else {
                name = line;
                host = line;
            }
            if (!host) {
                return null;
            }
            return { name: name || host, host: host };
        }).filter(Boolean);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function formatStatusClass(status) {
        if (!status) {
            return "";
        }
        return "ds-status-" + status.toLowerCase();
    }

    function formatHealthClass(health) {
        if (!health) {
            return "";
        }
        return "ds-health-" + health.toLowerCase();
    }

    function renderServer(server, data) {
        var rows = "";
        data.forEach(function(item) {
            rows += "<tr>" +
                "<td>" + escapeHtml(item.name) + "</td>" +
                "<td class=\"" + formatStatusClass(item.status) + "\">" + escapeHtml(item.status) + "</td>" +
                "<td>" + escapeHtml(item.uptime) + "</td>" +
                "<td>" + escapeHtml(item.cpu) + "%</td>" +
                "<td>" + escapeHtml(item.mem) + " MB</td>" +
                "<td>" + escapeHtml(item.restarts) + "</td>" +
                "<td class=\"" + formatHealthClass(item.health_class) + "\">" + escapeHtml(item.health) + "</td>" +
                "</tr>";
        });

        return "<div class=\"ds-server\">" +
            "<h5>" + escapeHtml(server.name) + "</h5>" +
            "<table>" +
            "<thead><tr><th>Name</th><th>Status</th><th>Uptime</th><th>CPU</th><th>Mem</th><th>Restarts</th><th>Health</th></tr></thead>" +
            "<tbody>" + (rows || "<tr><td colspan=\"7\" class=\"ds-muted\">No containers</td></tr>") + "</tbody>" +
            "</table>" +
            "</div>";
    }

    function renderError(server, error) {
        return "<div class=\"ds-server\">" +
            "<h5>" + escapeHtml(server.name) + "</h5>" +
            "<div class=\"ds-muted\">" + escapeHtml(error) + "</div>" +
            "</div>";
    }

    function loadData() {
        var servers = parseServers(serversInput.value || "");
        if (!servers.length) {
            content.textContent = "No servers configured.";
            return;
        }

        var html = "";
        var remaining = servers.length;
        servers.forEach(function(server, index) {
            fetch(endpoint + "?action=fetch&server=" + index, { credentials: "same-origin" })
                .then(function(response) { return response.json(); })
                .then(function(payload) {
                    if (payload.ok) {
                        html += renderServer(server, payload.data || []);
                    } else {
                        html += renderError(server, payload.error || "error");
                    }
                })
                .catch(function(error) {
                    html += renderError(server, error.message || "error");
                })
                .finally(function() {
                    remaining -= 1;
                    if (remaining === 0) {
                        content.innerHTML = html || "<div class=\"ds-muted\">No data</div>";
                    }
                });
        });
    }

    toggle.addEventListener("click", function() {
        settings.style.display = settings.style.display === "none" ? "block" : "none";
    });

    saveButton.addEventListener("click", function() {
        var body = new FormData();
        body.append("action", "save");
        body.append("servers", serversInput.value);
        body.append("refresh", refreshInput.value);
        saveStatus.textContent = "Saving...";
        fetch(endpoint, { method: "POST", body: body, credentials: "same-origin" })
            .then(function(response) { return response.json(); })
            .then(function(payload) {
                if (payload.ok) {
                    saveStatus.textContent = "Saved";
                    refreshSeconds = payload.refresh || refreshSeconds;
                    loadData();
                    scheduleRefresh();
                } else {
                    saveStatus.textContent = payload.error || "Error";
                }
            })
            .catch(function(error) {
                saveStatus.textContent = error.message || "Error";
            });
    });

    var refreshTimer = null;

    function scheduleRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }
        refreshTimer = setInterval(loadData, refreshSeconds * 1000);
    }

    loadData();
    scheduleRefresh();
})();
</script>
