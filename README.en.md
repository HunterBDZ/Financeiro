<div align="center">

<img src="public/assets/img/marca.svg" alt="" width="96">

# Financeiro

**Track your household money on your own computer: add expenses in seconds, see how the month will end, and plan purchases, debts and investments.**

No subscription, no cloud, and it never asks for your bank password.

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white) ![SQLite](https://img.shields.io/badge/SQLite-003B57?logo=sqlite&logoColor=white) ![No dependencies](https://img.shields.io/badge/dependencies-none-success)

[Português](README.md)

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-inicio-escuro.jpg">
  <img src="docs/img/pc-inicio-claro.jpg" alt="Home screen: account balance, month summary, latest entries and where the money went" width="860">
</picture>

<sub>All screenshots use made-up data from the demo mode. The app also runs in English and Spanish; the screenshots are in Portuguese.</sub>

</div>

## What it does

- **See your account's future:** month by month, see how much you will have and try "what if" without saving anything.
- **Find the best month to buy:** paste a product link, in any currency, and the app shows when the purchase fits your plan.
- **Find out if paying a loan early is worth it:** type an amount and see what is left, when it ends and how much interest you save.
- **Know your next card bills:** add installment purchases and see each bill before it arrives.
- **Get a report that explains your month:** download a PDF, by month or year, with charts, what went well, what went badly and what can improve.
- **Pick your look:** 32 themes with preview, including high-contrast and color-blind options.

## Install

**On 64-bit Windows 10 or 11.** Download `financeiro-…-windows-x86_64.exe` from [Releases](https://github.com/HunterBDZ/Financeiro/releases/latest) and double-click it. It does not ask for administrator rights. At the end, the site opens in your browser and you create your account.

**On a Mac with an Apple chip (macOS 12 or newer).** Download `financeiro-…-macos-arm64.dmg` from [Releases](https://github.com/HunterBDZ/Financeiro/releases/latest), drag Financeiro to Applications and open it from there. Intel Macs are not supported yet.

> [!WARNING]
> The Windows and Mac installers are new and have not been tried on real computers yet. Without a paid signature, the system warns you when opening them: the [installation manual, in Portuguese,](docs/INSTALACAO.md) shows what to do.

**On Linux.** Download the package from [Releases](https://github.com/HunterBDZ/Financeiro/releases/latest) and run, without `sudo`:

```sh
tar xzf financeiro-*-linux-*.tar.gz
cd financeiro-*/
./instalar
```

Open `http://localhost:8484` and create your account. None of these needs a database installed. To use it on your phone, open **Settings → Use on your phone** and scan the code with the camera.

**On an Ubuntu or Debian server:**

```sh
git clone https://github.com/HunterBDZ/Financeiro.git
sudo bash Financeiro/instalar.sh
```

**With Docker:** `docker compose up -d`, in the repository folder.

## Update and back up

In **Settings → Backup and updates**, you can look for a new version, update, download a copy of your data and restore a backup, without a terminal. The app also makes one copy a day.

Nothing updates by itself. Before applying a version, the app checks its signature and tests it on a copy of your data. If something fails, it goes back by itself. On a server, the same steps run with `sudo financeiro-atualizar`.

## Documentation

The detailed documentation is in Portuguese:

- [Installation manual](docs/INSTALACAO.md)
- [Inside the app](docs/POR-DENTRO.md)
- [Security](docs/SEGURANCA.md), including what leaves your computer
- [Decisions](docs/decisoes.md)
- [Contributing](CONTRIBUTING.md) and [reporting a vulnerability](SECURITY.md)

## License

© 2026 Hunter. Released under the [AGPL-3.0-or-later](LICENSE).

Third-party components: Manrope, Sora and JetBrains Mono fonts under the OFL and Noto Emoji icons under Apache-2.0. See the [third-party notices](THIRD_PARTY_NOTICES.md).
