/*
 * Copyright (C) 2026
 * All rights reserved.
 */

export default class DockerStatus extends BaseWidget {
    constructor(config) {
        super(config);
        this.tickTimeout = 10;
        this.refreshSeconds = 30;
        this.lastUpdate = 0;
        this.loading = false;
    }

    getMarkup() {
        const id = this.id;
        return $(`
            <div class="dockerstatus-widget" id="dockerstatus-${id}">
                <style>
                    .dockerstatus-widget .ds-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
                    .dockerstatus-widget .ds-settings { margin-bottom: 8px; }
                    .dockerstatus-widget .ds-settings textarea { width: 100%; min-height: 80px; }
                    .dockerstatus-widget .ds-server { border: 1px solid #d8d8d8; padding: 6px; margin-bottom: 8px; border-radius: 4px; }
                    .dockerstatus-widget .ds-server h5 { margin: 0 0 6px 0; font-weight: 600; }
                    .dockerstatus-widget table { width: 100%; border-collapse: collapse; }
                    .dockerstatus-widget th, .dockerstatus-widget td { padding: 3px 4px; border-bottom: 1px solid #efefef; font-size: 12px; }
                    .dockerstatus-widget .ds-status-running { color: #2c7a2c; font-weight: 600; }
                    .dockerstatus-widget .ds-status-exited { color: #a03c3c; font-weight: 600; }
                    .dockerstatus-widget .ds-status-paused { color: #a0702c; font-weight: 600; }
                    .dockerstatus-widget .ds-health-healthy { color: #2c7a2c; font-weight: 600; }
                    .dockerstatus-widget .ds-health-unhealthy { color: #a03c3c; font-weight: 600; }
                    .dockerstatus-widget .ds-muted { color: #777; font-size: 12px; }
                </style>

                <div class="ds-toolbar">
                    <div class="ds-muted">${this.translations.title || 'Docker Status'}</div>
                    <button type="button" class="btn btn-xs btn-default ds-toggle">Settings</button>
                </div>

                <div class="ds-settings" style="display: none;">
                    <label>${this.translations.servers || 'Servers (one per line, format: name|host or host)'}</label>
                    <textarea class="form-control ds-servers"></textarea>
                    <label style="margin-top: 6px;">${this.translations.refresh || 'Refresh (seconds)'}</label>
                    <input type="number" class="form-control ds-refresh" min="5" value="30">
                    <button type="button" class="btn btn-xs btn-primary ds-save" style="margin-top: 6px;">${this.translations.save || 'Save'}</button>
                    <span class="ds-save-status ds-muted" style="margin-left: 6px;"></span>
                </div>

                <div class="ds-content ds-muted"></div>
            </div>
        `);
    }

    async onMarkupRendered() {
        this._applyConfig();
        this._bindEvents();
        await this._loadData(true);
    }

    async onWidgetTick() {
        if (this.loading) {
            return;
        }
        const now = Date.now();
        if (now - this.lastUpdate < this.refreshSeconds * 1000) {
            return;
        }
        await this._loadData(false);
    }

    _applyConfig() {
        const config = this.config.widget || {};
        const serversText = config.servers_text || '';
        const refresh = parseInt(config.refresh_seconds, 10);

        this.refreshSeconds = Number.isFinite(refresh) && refresh >= 5 ? refresh : 30;

        const root = this._root();
        root.find('.ds-servers').val(serversText);
        root.find('.ds-refresh').val(this.refreshSeconds);
    }

    _bindEvents() {
        const root = this._root();
        root.find('.ds-toggle').on('click', () => {
            const panel = root.find('.ds-settings');
            panel.toggle();
        });

        root.find('.ds-save').on('click', async () => {
            const serversText = root.find('.ds-servers').val();
            const refresh = parseInt(root.find('.ds-refresh').val(), 10);
            const refreshSeconds = Number.isFinite(refresh) && refresh >= 5 ? refresh : 5;
            root.find('.ds-refresh').val(refreshSeconds);

            this.setWidgetConfig({
                servers_text: serversText,
                refresh_seconds: refreshSeconds
            });

            this.refreshSeconds = refreshSeconds;
            this.lastUpdate = 0;

            root.find('.ds-save-status').text(this.translations.saved || 'Saved. Click Save to persist.');
            $('#save-grid').show();

            await this._loadData(true);
        });
    }

    async _loadData(force) {
        const root = this._root();
        const serversText = root.find('.ds-servers').val() || '';
        const servers = this._parseServers(serversText);

        if (!servers.length) {
            root.find('.ds-content').text(this.translations.noservers || 'No servers configured.');
            this.lastUpdate = Date.now();
            return;
        }

        if (!force && !this.dataChanged('servers', servers)) {
            // no server list changes, continue
        }

        this.loading = true;
        root.find('.ds-content').html(`<div class="ds-muted">${this.translations.loading || 'Loading...'}</div>`);

        const results = await Promise.all(servers.map(async (server) => {
            try {
                const payload = await this.ajaxCall(`/api/dockerstatus/status/containers?host=${encodeURIComponent(server.host)}`);
                if (payload && payload.result === 'ok' && Array.isArray(payload.data)) {
                    return { server, ok: true, data: payload.data };
                }
                return { server, ok: false, error: payload && payload.message ? payload.message : 'error' };
            } catch (error) {
                const message = error && (error.textStatus || error.errorThrown) ? (error.textStatus || error.errorThrown) : 'error';
                return { server, ok: false, error: message };
            }
        }));

        let html = '';
        results.forEach((result) => {
            if (result.ok) {
                html += this._renderServer(result.server, result.data);
            } else {
                html += this._renderError(result.server, result.error);
            }
        });

        root.find('.ds-content').html(html);
        this.lastUpdate = Date.now();
        this.loading = false;

        if (this.config && this.config.callbacks && this.config.callbacks.updateGrid) {
            this.config.callbacks.updateGrid();
        }
    }

    _parseServers(text) {
        return text.split(/\r\n|\r|\n/).map((line) => {
            const trimmed = line.trim();
            if (!trimmed || trimmed.indexOf('#') === 0) {
                return null;
            }

            let name = '';
            let host = '';
            if (trimmed.indexOf('|') !== -1) {
                const parts = trimmed.split('|');
                name = parts[0].trim();
                host = parts.slice(1).join('|').trim();
            } else if (trimmed.indexOf(',') !== -1) {
                const parts = trimmed.split(',');
                name = parts[0].trim();
                host = parts.slice(1).join(',').trim();
            } else {
                name = trimmed;
                host = trimmed;
            }

            if (!host) {
                return null;
            }

            return { name: name || host, host: host };
        }).filter(Boolean);
    }

    _renderServer(server, data) {
        const rows = data.map((item) => {
            const statusClass = this._statusClass(item.status);
            const healthClass = this._healthClass(item.health_class || item.health);
            return `
                <tr>
                    <td>${this._escape(item.name)}</td>
                    <td class="${statusClass}">${this._escape(item.status)}</td>
                    <td>${this._escape(item.uptime)}</td>
                    <td>${this._escape(item.cpu)}%</td>
                    <td>${this._escape(item.mem)} MB</td>
                    <td>${this._escape(item.restarts)}</td>
                    <td class="${healthClass}">${this._escape(item.health)}</td>
                </tr>
            `;
        }).join('');

        const header = `
            <thead>
                <tr>
                    <th>${this.translations.name || 'Name'}</th>
                    <th>${this.translations.status || 'Status'}</th>
                    <th>${this.translations.uptime || 'Uptime'}</th>
                    <th>${this.translations.cpu || 'CPU'}</th>
                    <th>${this.translations.mem || 'Mem'}</th>
                    <th>${this.translations.restarts || 'Restarts'}</th>
                    <th>${this.translations.health || 'Health'}</th>
                </tr>
            </thead>
        `;

        const body = rows || `<tr><td colspan="7" class="ds-muted">${this.translations.nocontainers || 'No containers'}</td></tr>`;

        return `
            <div class="ds-server">
                <h5>${this._escape(server.name)}</h5>
                <table>
                    ${header}
                    <tbody>${body}</tbody>
                </table>
            </div>
        `;
    }

    _renderError(server, error) {
        return `
            <div class="ds-server">
                <h5>${this._escape(server.name)}</h5>
                <div class="ds-muted">${this._escape(error || 'error')}</div>
            </div>
        `;
    }

    _statusClass(status) {
        if (!status) {
            return '';
        }
        return 'ds-status-' + String(status).toLowerCase();
    }

    _healthClass(health) {
        if (!health) {
            return '';
        }
        return 'ds-health-' + String(health).toLowerCase();
    }

    _escape(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    _root() {
        return $(`#dockerstatus-${this.id}`);
    }
}
