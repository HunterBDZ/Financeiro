#!/usr/bin/env python3
"""Monta financeiro.ico e financeiro.icns a partir de PNGs da marca (public/assets/img/marca.svg).
Uso: montar.py PASTA  com i16.png ... i256.png (Windows) e m128.png ... m1024.png (Mac, com a margem que o Mac espera)."""
import struct, sys
from pathlib import Path

pasta = Path(sys.argv[1])
destino = Path(__file__).parent

tamanhos = [16, 24, 32, 48, 64, 128, 256]
imagens = [(pasta / f"i{t}.png").read_bytes() for t in tamanhos]
cabeca = struct.pack("<HHH", 0, 1, len(imagens))
inicio = 6 + 16 * len(imagens)
for t, png in zip(tamanhos, imagens):
    cabeca += struct.pack("<BBBBHHII", t % 256, t % 256, 0, 0, 1, 32, len(png), inicio)
    inicio += len(png)
(destino / "financeiro.ico").write_bytes(cabeca + b"".join(imagens))

corpo = b""
for tipo, t in ((b"ic07", 128), (b"ic08", 256), (b"ic09", 512), (b"ic10", 1024)):
    png = (pasta / f"m{t}.png").read_bytes()
    corpo += tipo + struct.pack(">I", len(png) + 8) + png
(destino / "financeiro.icns").write_bytes(b"icns" + struct.pack(">I", len(corpo) + 8) + corpo)
