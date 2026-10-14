<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ScanPPP\Network;

$serverIp = Network::primaryIp();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanPPP — сканер сети</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Roboto+Mono&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body data-server-ip="<?= htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8') ?>">
    <div class="container">
        <header class="app-header">
            <h1>ScanPPP</h1>
            <p>Сканирование локальной сети: компьютеры, IP-телефоны и принтеры</p>
        </header>

        <nav class="tabs">
            <button class="tab active" data-tab="pc">
                <span class="material-icons">computer</span> Компьютеры
            </button>
            <button class="tab" data-tab="phone">
                <span class="material-icons">phone</span> IP-телефоны
            </button>
            <button class="tab" data-tab="printer">
                <span class="material-icons">print</span> Принтеры
            </button>
        </nav>

        <section class="panel active" data-panel="pc">
            <div class="card">
                <div class="form-block">
                    <select data-role="preset"></select>
                    <button class="scan-button" data-role="start">
                        <span class="material-icons">search</span>
                        <span data-role="start-label">Начать сканирование</span>
                    </button>
                    <input type="text" data-role="custom" placeholder="192.168.1.1-192.168.1.254" style="display:none">
                    <span class="range-hint" data-role="range-hint"></span>
                </div>

                <div class="error-message" data-role="error"></div>

                <div class="progress-container" data-role="progress">
                    <div class="scan-range" data-role="range"></div>
                    <div class="progress-info">
                        <span>Сканирование...</span>
                        <span class="current-ip" data-role="current">-</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-role="fill"></div>
                    </div>
                    <div class="scan-stats">
                        <div class="stat-item">
                            <span class="material-icons">query_stats</span>
                            <span>Просканировано: <span data-role="scanned">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">computer</span>
                            <span>Найдено компьютеров: <span data-role="found">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">speed</span>
                            <span>Скорость: <span data-role="speed">0 IP/сек</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">timer</span>
                            <span>Время: <span data-role="time">00:00:00</span></span>
                        </div>
                    </div>
                </div>

                <div class="results-section">
                    <h3>Найденные компьютеры</h3>
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>IP адрес</th>
                                <th>Имя компьютера</th>
                                <th>MAC адрес</th>
                            </tr>
                        </thead>
                        <tbody data-role="results"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel" data-panel="phone">
            <div class="card">
                <div class="form-block">
                    <select data-role="preset"></select>
                    <button class="scan-button" data-role="start">
                        <span class="material-icons">search</span>
                        <span data-role="start-label">Начать сканирование</span>
                    </button>
                    <input type="text" data-role="custom" placeholder="192.168.1.1-192.168.1.254" style="display:none">
                    <span class="range-hint" data-role="range-hint"></span>
                </div>

                <div class="error-message" data-role="error"></div>

                <div class="progress-container" data-role="progress">
                    <div class="scan-range" data-role="range"></div>
                    <div class="progress-info">
                        <span>Сканирование...</span>
                        <span class="current-ip" data-role="current">-</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-role="fill"></div>
                    </div>
                    <div class="scan-stats">
                        <div class="stat-item">
                            <span class="material-icons">query_stats</span>
                            <span>Просканировано: <span data-role="scanned">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">phone</span>
                            <span>Найдено телефонов: <span data-role="found">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">devices</span>
                            <span>Других устройств: <span data-role="other">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">speed</span>
                            <span>Скорость: <span data-role="speed">0 IP/сек</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">timer</span>
                            <span>Время: <span data-role="time">00:00:00</span></span>
                        </div>
                    </div>
                </div>

                <div class="results-section">
                    <h3>Найденные IP-телефоны</h3>
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>IP адрес</th>
                                <th>MAC адрес</th>
                                <th>Название</th>
                                <th>Модель</th>
                                <th>Доступность</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody data-role="results"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel" data-panel="printer">
            <div class="card">
                <div class="form-block">
                    <select data-role="preset"></select>
                    <button class="scan-button" data-role="start">
                        <span class="material-icons">search</span>
                        <span data-role="start-label">Начать сканирование</span>
                    </button>
                    <input type="text" data-role="custom" placeholder="192.168.1.1-192.168.1.254" style="display:none">
                    <span class="range-hint" data-role="range-hint"></span>
                </div>

                <div class="error-message" data-role="error"></div>

                <div class="progress-container" data-role="progress">
                    <div class="scan-range" data-role="range"></div>
                    <div class="progress-info">
                        <span>Сканирование...</span>
                        <span class="current-ip" data-role="current">-</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-role="fill"></div>
                    </div>
                    <div class="scan-stats">
                        <div class="stat-item">
                            <span class="material-icons">query_stats</span>
                            <span>Просканировано: <span data-role="scanned">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">print</span>
                            <span>Найдено принтеров: <span data-role="found">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">devices</span>
                            <span>Других устройств: <span data-role="other">0</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">speed</span>
                            <span>Скорость: <span data-role="speed">0 IP/сек</span></span>
                        </div>
                        <div class="stat-item">
                            <span class="material-icons">timer</span>
                            <span>Время: <span data-role="time">00:00:00</span></span>
                        </div>
                    </div>
                </div>

                <div class="results-section">
                    <h3>Найденные принтеры</h3>
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>IP адрес</th>
                                <th>Имя устройства</th>
                                <th>Модель</th>
                                <th>Серийный номер</th>
                                <th>Картриджи</th>
                                <th>Web</th>
                            </tr>
                        </thead>
                        <tbody data-role="results"></tbody>
                    </table>
                </div>

                <div class="other-devices-section">
                    <div class="section-header" data-action="toggle-other">
                        <h3>Другие SNMP-устройства</h3>
                        <span class="toggle-icon material-icons">expand_more</span>
                    </div>
                    <div class="other-devices-container">
                        <table class="results-table">
                            <thead>
                                <tr>
                                <th>IP адрес</th>
                                <th>Имя устройства</th>
                                <th>Описание</th>
                                <th>Тип устройства</th>
                                <th>Web</th>
                                </tr>
                            </thead>
                            <tbody data-role="otherResults"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <footer class="app-footer">
            Made with <span class="heart">&#10084;&#65039;</span> by Sergey Nemo 2026
        </footer>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
