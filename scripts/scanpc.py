#!/usr/bin/env python3
"""Помощник сканера компьютеров ScanPPP.

Определяет, является ли IP-адрес рабочей станцией Windows, и печатает JSON
с адресом, именем хоста и MAC-адресом. Вызывается из PHP (PcScanner).

Использование:
    python scanpc.py 192.168.0.10
"""

import json
import re
import socket
import subprocess
import sys


def get_hostname(ip):
    try:
        return socket.gethostbyaddr(ip)[0]
    except Exception:
        return "Unknown"


def check_snmp(ip):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(0.5)
        opened = sock.connect_ex((ip, 161)) == 0
        sock.close()
        return opened
    except Exception:
        return False


def check_windows_ports(ip):
    try:
        for port in (135, 445):
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(0.2)
            opened = sock.connect_ex((ip, port)) == 0
            sock.close()
            if opened:
                return True
        return False
    except Exception:
        return False


def get_mac_address(ip):
    try:
        subprocess.run(["ping", "-n", "2", ip],
                       stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL)

        try:
            output = subprocess.check_output(["arp", "-a", ip], shell=True).decode("cp866", errors="ignore")
            for line in output.split("\n"):
                if ip in line:
                    parts = line.strip().split()
                    if len(parts) >= 2:
                        mac = parts[1].replace("-", ":").upper()
                        if len(mac) == 17 and mac.count(":") == 5:
                            return mac
        except Exception:
            pass

        try:
            output = subprocess.check_output("arp -a", shell=True).decode("cp866", errors="ignore")
            for line in output.split("\n"):
                if ip in line:
                    parts = line.strip().split()
                    if len(parts) >= 2:
                        mac = parts[1].replace("-", ":").upper()
                        if len(mac) == 17 and mac.count(":") == 5:
                            return mac
        except Exception:
            pass

        try:
            output = subprocess.check_output(["nbtstat", "-a", ip], shell=True).decode("cp866", errors="ignore")
            match = re.search(r"MAC Address = ([0-9A-F]{2}(?:-[0-9A-F]{2}){5})", output)
            if match:
                return match.group(1).replace("-", ":")
        except Exception:
            pass
    except Exception:
        pass

    return "Unknown"


def scan_ip(ip):
    ping_ok = subprocess.run(["ping", "-n", "1", "-w", "100", ip],
                             stdout=subprocess.DEVNULL,
                             stderr=subprocess.DEVNULL).returncode == 0
    if not ping_ok:
        return None

    if check_snmp(ip):
        return None

    if not check_windows_ports(ip):
        return None

    hostname = get_hostname(ip)
    if hostname == "Unknown":
        return None

    return {
        "ip": ip,
        "mac": get_mac_address(ip),
        "hostname": hostname,
        "type": "computer",
    }


def main():
    if len(sys.argv) < 2:
        sys.stderr.write("usage: scanpc.py <ip>\n")
        return 2

    device = scan_ip(sys.argv[1].strip())
    if device:
        print(json.dumps(device))
        sys.stdout.flush()

    return 0


if __name__ == "__main__":
    sys.exit(main())
