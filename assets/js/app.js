(function () {
    'use strict';

    const CUSTOM_VALUE = '__custom__';

    const SERVER_IP = document.body ? (document.body.dataset.serverIp || '') : '';

    function localSubnet(ip) {
        const parts = String(ip).split('.');
        if (parts.length !== 4) {
            return null;
        }
        if (parts[0] === '127' || parts[0] === '0') {
            return null;
        }

        return parts[0] + '.' + parts[1] + '.' + parts[2];
    }

    function buildRangePresets(serverIp) {
        const presets = [];

        for (let i = 0; i <= 5; i++) {
            presets.push('192.168.' + i + '.1-192.168.' + i + '.254');
        }

        const prefix = localSubnet(serverIp);
        if (prefix) {
            const local = prefix + '.1-' + prefix + '.254';
            if (!presets.includes(local)) {
                presets.push(local);
            }
        }

        return presets;
    }

    const RANGE_PRESETS = buildRangePresets(SERVER_IP);

    const Ip = {
        toLong(ip) {
            const parts = String(ip).trim().split('.');
            if (parts.length !== 4) {
                return null;
            }

            let long = 0;
            for (const part of parts) {
                if (!/^\d{1,3}$/.test(part)) {
                    return null;
                }
                const num = Number(part);
                if (num > 255) {
                    return null;
                }
                long = long * 256 + num;
            }

            return long;
        },

        fromLong(long) {
            return [
                Math.floor(long / 16777216) % 256,
                Math.floor(long / 65536) % 256,
                Math.floor(long / 256) % 256,
                long % 256,
            ].join('.');
        },

        parseRange(value) {
            const clean = String(value || '').trim();
            if (!clean) {
                throw new Error('Введите диапазон IP-адресов');
            }

            const parts = clean.split('-').map((part) => part.trim());
            const startRaw = parts[0];
            const endRaw = parts[1];

            if (!startRaw) {
                throw new Error('Неверный формат диапазона');
            }

            const start = Ip.toLong(startRaw);
            if (start === null) {
                throw new Error('Неверный формат IP-адреса');
            }

            if (!endRaw) {
                return [startRaw];
            }

            const end = Ip.toLong(endRaw);
            if (end === null) {
                throw new Error('Неверный формат IP-адреса');
            }
            if (start > end) {
                throw new Error('Начало диапазона больше его конца');
            }
            if (end - start > 255) {
                throw new Error('Поддерживается диапазон в пределах одной подсети (/24)');
            }

            const ips = [];
            for (let long = start; long <= end; long++) {
                ips.push(Ip.fromLong(long));
            }

            return ips;
        },
    };

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDuration(ms) {
        const total = Math.floor(ms / 1000);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = total % 60;
        const pad = (value) => String(value).padStart(2, '0');

        return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    }

    async function fetchJson(url, signal) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: signal });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return response.json();
    }

    /**
     * Движок сканирования: пул воркеров берёт адреса из общей очереди,
     * раз в секунду рассылает прогресс (onTick) и поддерживает отмену
     * через AbortController.
     */
    class ScanController {
        constructor(options) {
            this.concurrency = options.concurrency || 8;
            this.scan = options.scan;
            this.onResult = options.onResult;
            this.onTick = options.onTick;

            this.queue = [];
            this.index = 0;
            this.scanned = 0;
            this.speed = 0;
            this.startedAt = 0;
            this.lastTime = 0;
            this.lastScanned = 0;
            this.timer = null;
            this.stopRequested = false;
            this.abort = null;
        }

        async start(ips) {
            this.queue = ips.slice();
            this.index = 0;
            this.scanned = 0;
            this.speed = 0;
            this.stopRequested = false;
            this.startedAt = Date.now();
            this.lastTime = Date.now();
            this.lastScanned = 0;
            this.abort = new AbortController();

            this.timer = setInterval(() => this.tick(), 1000);
            this.onTick(this.snapshot());

            const workerCount = Math.min(this.concurrency, this.queue.length);
            const workers = [];
            for (let i = 0; i < workerCount; i++) {
                workers.push(this.runWorker());
            }

            await Promise.all(workers);

            clearInterval(this.timer);
            this.timer = null;

            this.onTick(this.snapshot());
        }

        async runWorker() {
            while (!this.stopRequested) {
                const ip = this.queue[this.index++];
                if (ip === undefined) {
                    return;
                }

                let result = null;
                try {
                    result = await this.scan(ip, this.abort.signal);
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    console.error('Ошибка сканирования ' + ip, error);
                }

                if (this.stopRequested) {
                    return;
                }

                this.scanned++;
                if (result) {
                    this.onResult(ip, result);
                }
            }
        }

        stop() {
            this.stopRequested = true;
            if (this.abort) {
                this.abort.abort();
            }
        }

        tick() {
            const now = Date.now();
            const seconds = (now - this.lastTime) / 1000;
            const delta = this.scanned - this.lastScanned;

            if (seconds > 0) {
                this.speed = Math.round(delta / seconds);
            }

            this.lastTime = now;
            this.lastScanned = this.scanned;
            this.onTick(this.snapshot());
        }

        snapshot() {
            const total = this.queue.length || 1;

            return {
                scanned: this.scanned,
                progress: Math.min(100, Math.round((this.scanned / total) * 100)),
                speed: this.speed,
                elapsed: Date.now() - this.startedAt,
            };
        }
    }

    /**
     * Панель одной вкладки: выпадающий список диапазонов, валидация
     * ручного ввода, запуск движка и отрисовка найденных устройств.
     */
    class ScannerPanel {
        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.engine = null;
            this.running = false;
            this.found = 0;
            this.other = 0;

            const role = (name) => root.querySelector('[data-role="' + name + '"]');
            this.els = {
                preset: role('preset'),
                custom: role('custom'),
                start: role('start'),
                startLabel: role('start-label'),
                error: role('error'),
                progress: role('progress'),
                range: role('range'),
                current: role('current'),
                fill: role('fill'),
                scanned: role('scanned'),
                found: role('found'),
                other: role('other'),
                speed: role('speed'),
                time: role('time'),
                results: role('results'),
                otherResults: role('otherResults'),
                hint: role('range-hint'),
            };

            this.scanningOther = false;
            this.customValid = true;

            this.buildPresets();
            this.bindEvents();
            this.updateRangeHint();
            this.applyButtonState();
        }

        buildPresets() {
            if (!this.els.preset) {
                return;
            }

            this.els.preset.innerHTML = '';

            RANGE_PRESETS.forEach((range) => {
                const option = document.createElement('option');
                option.value = range;
                option.textContent = range;
                this.els.preset.appendChild(option);
            });

            const custom = document.createElement('option');
            custom.value = CUSTOM_VALUE;
            custom.textContent = 'Ввести вручную…';
            this.els.preset.appendChild(custom);

            const prefix = localSubnet(SERVER_IP);
            const localRange = prefix ? prefix + '.1-' + prefix + '.254' : '';
            this.els.preset.value = (localRange && RANGE_PRESETS.includes(localRange))
                ? localRange
                : RANGE_PRESETS[0];
        }

        bindEvents() {
            if (this.els.preset) {
                this.els.preset.addEventListener('change', () => this.toggleCustom());
            }
            if (this.els.start) {
                this.els.start.addEventListener('click', () => this.start());
            }
            if (this.els.custom) {
                this.els.custom.addEventListener('input', () => this.validateCustomInput());
                this.els.custom.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        this.start();
                    }
                });
            }
        }

        validateCustomInput() {
            const input = this.els.custom;
            if (!input) {
                return;
            }

            const value = input.value.trim();
            let valid = false;

            if (value !== '') {
                try {
                    Ip.parseRange(value);
                    valid = true;
                } catch (error) {
                    valid = false;
                }
            }

            input.classList.toggle('invalid', value !== '' && !valid);
            input.setCustomValidity(valid ? '' : 'Формат: 192.168.1.1-192.168.1.254');

            this.customValid = valid;
            this.updateRangeHint();
            this.applyButtonState();
        }

        updateRangeHint() {
            if (!this.els.hint) {
                return;
            }

            const isCustom = this.els.preset && this.els.preset.value === CUSTOM_VALUE;
            if (!isCustom) {
                this.els.hint.textContent = '';
                this.els.hint.className = 'range-hint';
                this.els.hint.style.display = 'none';
                return;
            }

            this.els.hint.style.display = '';
            const value = this.els.custom ? this.els.custom.value.trim() : '';
            if (value === '') {
                this.els.hint.textContent = 'Введите диапазон';
                this.els.hint.className = 'range-hint error';
            } else if (this.customValid) {
                this.els.hint.textContent = 'Диапазон введён правильно';
                this.els.hint.className = 'range-hint ok';
            } else {
                this.els.hint.textContent = 'Нужно ввести правильно';
                this.els.hint.className = 'range-hint error';
            }
        }

        computeDisabled() {
            if (this.running) {
                return false;
            }
            if (this.scanningOther) {
                return true;
            }

            const isCustom = this.els.preset && this.els.preset.value === CUSTOM_VALUE;
            if (isCustom && !this.customValid) {
                return true;
            }

            return false;
        }

        applyButtonState() {
            if (this.els.start) {
                this.els.start.disabled = this.computeDisabled();
            }
        }

        toggleCustom() {
            const isCustom = this.els.preset && this.els.preset.value === CUSTOM_VALUE;
            if (this.els.custom) {
                this.els.custom.style.display = isCustom ? 'block' : 'none';
                if (isCustom) {
                    this.els.custom.focus();
                }
            }
            this.updateRangeHint();
            this.applyButtonState();
        }

        resolveRange() {
            let value = this.els.preset ? this.els.preset.value : '';
            if (value === CUSTOM_VALUE) {
                value = this.els.custom ? this.els.custom.value : '';
            }

            return Ip.parseRange(value);
        }

        setButton(mode) {
            if (!this.els.start) {
                return;
            }

            const icon = this.els.start.querySelector('.material-icons');
            const running = mode === 'running';

            this.els.start.classList.toggle('stop', running);
            if (icon) {
                icon.textContent = running ? 'stop' : 'search';
            }
            if (this.els.startLabel) {
                this.els.startLabel.textContent = running ? 'Остановить' : 'Начать сканирование';
            }
        }

        showError(message) {
            if (!this.els.error) {
                return;
            }

            this.els.error.textContent = message || '';
            this.els.error.style.display = message ? 'block' : 'none';
        }

        async start() {
            if (this.running) {
                if (this.engine) {
                    this.engine.stop();
                }
                if (this.els.startLabel) {
                    this.els.startLabel.textContent = 'Остановка…';
                }
                return;
            }

            let ips;
            try {
                ips = this.resolveRange();
            } catch (error) {
                this.showError(error.message);
                return;
            }

            if (!ips.length) {
                this.showError('Не удалось разобрать диапазон IP-адресов');
                return;
            }

            this.showError('');
            this.running = true;
            this.found = 0;
            this.other = 0;
            if (this.els.results) {
                this.els.results.innerHTML = '';
            }
            if (this.els.otherResults) {
                this.els.otherResults.innerHTML = '';
            }
            if (this.els.progress) {
                this.els.progress.style.display = 'block';
            }
            if (this.els.range) {
                this.els.range.textContent =
                    'Диапазон: ' + ips[0] + ' - ' + ips[ips.length - 1] + ' (' + ips.length + ' адресов)';
            }
            if (this.els.current) {
                this.els.current.textContent = '-';
            }
            this.setButton('running');
            updateButtons(this);

            this.engine = new ScanController({
                concurrency: this.config.concurrency,
                scan: async (ip, signal) => {
                    if (this.els.current) {
                        this.els.current.textContent = ip;
                    }
                    return this.config.fetchIp(ip, signal);
                },
                onResult: (ip, result) => this.handleResult(ip, result),
                onTick: (snapshot) => this.updateStats(snapshot),
            });

            await this.engine.start(ips);

            this.running = false;
            this.setButton('idle');
            updateButtons(null);
            if (this.els.current) {
                this.els.current.textContent = this.engine.stopRequested
                    ? 'Сканирование остановлено'
                    : 'Сканирование завершено';
            }
        }

        handleResult(ip, result) {
            const category = this.config.onResult(ip, result, this);
            if (category === 'found') {
                this.found++;
            } else if (category === 'other') {
                this.other++;
            }
        }

        updateStats(snapshot) {
            if (this.els.fill) {
                this.els.fill.style.width = snapshot.progress + '%';
            }
            if (this.els.scanned) {
                this.els.scanned.textContent = snapshot.scanned;
            }
            if (this.els.found) {
                this.els.found.textContent = this.found;
            }
            if (this.els.other) {
                this.els.other.textContent = this.other;
            }
            if (this.els.speed) {
                this.els.speed.textContent = snapshot.speed + ' IP/сек';
            }
            if (this.els.time) {
                this.els.time.textContent = formatDuration(snapshot.elapsed);
            }
        }

        appendRow(tableBody, html) {
            if (!tableBody) {
                return;
            }

            const row = tableBody.insertRow();
            row.innerHTML = html;
        }
    }

    function webLinkHtml(url) {
        if (!url) {
            return '<span class="muted">—</span>';
        }

        return `<a class="link-btn" href="${escapeHtml(url)}" target="_blank" rel="noopener">`
            + '<span class="material-icons">open_in_new</span>Открыть</a>';
    }

    function gaugeClass(level) {
        if (level === null || level === undefined) {
            return 'gauge-fill-unknown';
        }
        if (level <= 20) {
            return 'gauge-fill-low';
        }
        if (level <= 50) {
            return 'gauge-fill-medium';
        }

        return 'gauge-fill-high';
    }

    function cartridgesHtml(cartridges) {
        if (!cartridges.length) {
            return '<span class="muted">нет данных</span>';
        }

        return cartridges.map((cartridge) => {
            const width = cartridge.level_int === null || cartridge.level_int === undefined
                ? 5
                : cartridge.level_int;

            return `
                <div class="cartridge-info">
                    <div class="cartridge-name">${escapeHtml(cartridge.name)}</div>
                    <div class="cartridge-level">${escapeHtml(cartridge.level)}</div>
                </div>
                <div class="gauge">
                    <div class="gauge-fill ${gaugeClass(cartridge.level_int)}" style="width:${width}%"></div>
                </div>
            `;
        }).join('');
    }

    const panels = [];

    const scannerConfigs = {
        pc: {
            concurrency: 3,
            async fetchIp(ip, signal) {
                const data = await fetchJson('api.php?scanner=pc&ip=' + encodeURIComponent(ip), signal);
                return data || null;
            },
            onResult(ip, device, panel) {
                panel.appendRow(panel.els.results, `
                    <td class="ip-address">${escapeHtml(ip)}</td>
                    <td class="hostname">${escapeHtml(device.hostname || 'Неизвестно')}</td>
                    <td class="mono">${escapeHtml(device.mac || 'Не определён')}</td>
                `);

                return 'found';
            },
        },

        phone: {
            concurrency: 8,
            async fetchIp(ip, signal) {
                return fetchJson('api.php?scanner=phone&ip=' + encodeURIComponent(ip), signal);
            },
            onResult(ip, device, panel) {
                const availability = [];
                if (device.sip_available) {
                    availability.push('<span class="chip success"><span class="material-icons">check</span>SIP</span>');
                }
                availability.push('<span class="chip success"><span class="material-icons">check</span>Web</span>');

                panel.appendRow(panel.els.results, `
                    <td class="ip-address">${escapeHtml(ip)}</td>
                    <td class="mono">${escapeHtml(device.mac || 'Не определён')}</td>
                    <td>${escapeHtml(device.title || 'Неизвестно')}</td>
                    <td><span class="chip"><span class="material-icons">phone</span>${escapeHtml(device.model || 'Не определена')}</span></td>
                    <td>${availability.join('')}</td>
                    <td>
                        <a class="link-btn" href="${escapeHtml(device.url || '#')}" target="_blank" rel="noopener">
                            <span class="material-icons">open_in_new</span>Открыть
                        </a>
                    </td>
                `);

                return 'found';
            },
        },

        printer: {
            concurrency: 4,
            async fetchIp(ip, signal) {
                const status = await fetchJson('api.php?scanner=printer&action=check&ip=' + encodeURIComponent(ip), signal);
                if (!status || !status.snmp) {
                    return null;
                }

                if (status.printer) {
                    const info = await fetchJson('api.php?scanner=printer&action=scan&ip=' + encodeURIComponent(ip), signal);
                    const hasData = info && (info.model || info.name || (info.cartridges && info.cartridges.length));

                    return hasData ? { kind: 'printer', info } : null;
                }

                const info = await fetchJson('api.php?scanner=printer&action=device&ip=' + encodeURIComponent(ip), signal);

                return info ? { kind: 'device', info } : null;
            },
            onResult(ip, result, panel) {
                if (result.kind === 'printer') {
                    const info = result.info;
                    panel.appendRow(panel.els.results, `
                        <td class="ip-address">${escapeHtml(ip)}</td>
                        <td class="hostname">${escapeHtml(info.name || 'Н/Д')}</td>
                        <td>${escapeHtml(info.model || 'Н/Д')}</td>
                        <td class="mono">${escapeHtml(info.serial || 'Н/Д')}</td>
                        <td class="cartridges-cell">${cartridgesHtml(info.cartridges || [])}</td>
                        <td>${webLinkHtml(info.url)}</td>
                    `);

                    return 'found';
                }

                const info = result.info;
                panel.appendRow(panel.els.otherResults, `
                    <td class="ip-address">${escapeHtml(ip)}</td>
                    <td class="hostname">${escapeHtml(info.name || 'Н/Д')}</td>
                    <td>${escapeHtml(info.description || 'Н/Д')}</td>
                    <td class="muted">${escapeHtml(info.type || 'Неизвестно')}</td>
                    <td>${webLinkHtml(info.url)}</td>
                `);

                return 'other';
            },
        },
    };

    function updateButtons(activePanel) {
        panels.forEach((panel) => {
            panel.scanningOther = activePanel !== null && panel !== activePanel;
            panel.applyButtonState();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach((item) => item.classList.remove('active'));
                document.querySelectorAll('.panel').forEach((item) => item.classList.remove('active'));
                tab.classList.add('active');

                const panel = document.querySelector('.panel[data-panel="' + tab.dataset.tab + '"]');
                if (panel) {
                    panel.classList.add('active');
                }
            });
        });

        document.querySelectorAll('.panel').forEach((panel) => {
            const config = scannerConfigs[panel.dataset.panel];
            if (config) {
                panels.push(new ScannerPanel(panel, config));
            }
        });

        const otherToggle = document.querySelector('[data-action="toggle-other"]');
        if (otherToggle) {
            otherToggle.addEventListener('click', () => {
                const container = document.querySelector('.other-devices-container');
                const icon = otherToggle.querySelector('.toggle-icon');
                if (container) {
                    container.classList.toggle('visible');
                }
                if (icon) {
                    icon.classList.toggle('rotated');
                }
            });
        }
    });
})();
