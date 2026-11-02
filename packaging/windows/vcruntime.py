#!/usr/bin/env python3
"""Tira as DLLs do runtime do Visual C++ de dentro do VC_redist.x64.exe, sem instalar nada. O PHP e o FrankenPHP para
Windows precisam delas, e levá-las ao lado do programa dispensa administrador.
Uso: vcruntime.py VC_redist.x64.exe PASTA  (precisa do 7z no PATH)"""
import re, shutil, struct, subprocess, sys, tempfile
from pathlib import Path

QUERO = ["vcruntime140.dll", "vcruntime140_1.dll"]
exe, destino = Path(sys.argv[1]), Path(sys.argv[2])
destino.mkdir(parents=True, exist_ok=True)
dados = exe.read_bytes()


def sete(*args):
    subprocess.run(["7z", *args], check=True, stdout=subprocess.DEVNULL)


with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    for n, m in enumerate(re.finditer(rb"MSCF\0\0\0\0", dados)):
        inicio = m.start()
        tamanho = struct.unpack("<I", dados[inicio + 8 : inicio + 12])[0]
        if tamanho < 1000 or inicio + tamanho > len(dados):
            continue
        cab = tmp / f"fora{n}.cab"
        cab.write_bytes(dados[inicio : inicio + tamanho])
        fora = tmp / f"fora{n}"
        sete("x", "-y", f"-o{fora}", str(cab))
        for dentro in fora.iterdir():
            if dentro.read_bytes()[:4] != b"MSCF":
                continue
            pasta = tmp / f"dentro{n}-{dentro.name}"
            sete("x", "-y", f"-o{pasta}", str(dentro))
            for nome in QUERO:
                achado = pasta / f"{nome}_amd64"
                if achado.exists():
                    shutil.copy(achado, destino / nome)

faltam = [nome for nome in QUERO if not (destino / nome).exists()]
if faltam:
    sys.exit(f"não achei no VC_redist: {', '.join(faltam)}")
