# RNV Sync

**Português** · [English](README.en.md)

> Um cliente OneDrive bonito e self-hosted para Linux — movido pelo
> [rclone](https://rclone.org/).

O RNV Sync dá aos usuários de Linux uma interface web limpa e com cara
de aplicativo nativo para gerenciar contas do OneDrive: sincronização
seletiva de pastas, arquivos sob demanda (☁ na nuvem / ✓ no
dispositivo), upload em tempo real, ícone na bandeja do sistema e
integração com o gerenciador de arquivos (emblemas).

É uma aplicação Laravel que empacota e orquestra o rclone — a relação
é parecida com a do GitHub Desktop com o git: o rclone é o motor
confiável e testado; o RNV Sync é a camada de experiência e
gerenciamento por cima.

![Painel](docs/images/painel.png)

## Por que usar

- **Sem terminal** — conecte contas e gerencie tudo pelo navegador.
- **Seus dados ficam no seu PC** — tokens criptografados; **zero
  telemetria**; o painel escuta só em `127.0.0.1`.
- **Movido pelo rclone** — o motor de sincronização em nuvem mais
  capaz que existe, empacotado **sem modificações**.
- **Tempo real** — alterou um arquivo, sobe pra nuvem em segundos.
- **Leve** — fica ocioso sem pesar na máquina.

## Como funciona (rápido)

1. Você conecta sua conta OneDrive (login Microsoft dentro do app).
2. Escolhe **quais pastas** sincronizar — nada é baixado por padrão;
   tudo aparece como nuvem ☁ no gerenciador de arquivos.
3. Clica num arquivo/pasta para **"Manter local"** (baixa de verdade,
   vira ✓) ou **"Apenas online"** (sobe e libera espaço, volta a ☁).
4. Alterações locais sobem sozinhas; alterações na nuvem descem nos
   itens que você mantém offline.

## Instalação

Requisitos: **PHP 8.3+**, **Composer**, **git** e Linux com systemd.
O instalador resolve o resto (extensão SQLite, rclone, inotify,
integração de desktop) — pedindo a senha numa **janela gráfica**
quando precisar, sem terminal de root.

```bash
git clone https://github.com/renanvolpato/rnv-sync.git
cd rnv-sync
bash install/bootstrap.sh   # dependências do sistema
bash install/install.sh     # instala em ~/.local/share/rnv-sync + serviços
```

Abra <http://localhost:8770> e conclua o assistente de configuração.
Detalhes e outras distros: [docs/installation.md](docs/installation.md).

## Atualizar

```bash
bash ~/.local/share/rnv-sync/install/update.sh
```

Um comando: baixa a versão nova, atualiza dependências, aplica
migrações, recompila a interface e reinicia os serviços. **Seus
arquivos sincronizados não são tocados.**

## Desinstalar

```bash
bash ~/.local/share/rnv-sync/install/uninstall.sh
```

Remove serviços, integrações e o app (pede confirmação). **Os
arquivos que você já sincronizou continuam no disco.**

## Telas

| Selecionar pastas | Pastas sincronizadas |
|---|---|
| ![Selecionar pastas](docs/images/pastas.png) | ![Sincronizadas](docs/images/sincronizadas.png) |

| Configurações | Tendências de armazenamento |
|---|---|
| ![Configurações](docs/images/config.png) | ![Tendências](docs/images/tendencias.png) |

No gerenciador de arquivos, cada item mostra seu estado: ☁ apenas
online, ⟳ sincronizando, ✓ disponível no dispositivo.

![Gerenciador de arquivos](docs/images/gerenciador.png)

## Funcionalidades

- Assistente de primeira execução; senha do painel com proteção
  contra força bruta
- OneDrive **Pessoal, Empresarial e SharePoint** via OAuth da
  Microsoft dentro do app (tokens criptografados, renovação automática)
- Sincronização seletiva de pastas; arquivos sob demanda (manter
  local / apenas online por item)
- **Upload em tempo real** (vigia de arquivos) + sincronização
  agendada de segurança
- Ícone na **bandeja do sistema** (animado enquanto sincroniza) e
  **emblemas no gerenciador** de arquivos
- Detecção e resolução visual de conflitos
- Limite de banda + agendador; busca entre contas; tendências de uso
- Exportar/importar configuração; PT-BR e EN; tema escuro
- Roda em segundo plano via systemd (sobrevive a logout/reboot),
  leve e ocioso

## Documentação

[Instalação](docs/installation.md) ·
[Configuração](docs/configuration.md) ·
[Uso](docs/usage.md) ·
[OAuth Microsoft](docs/oauth.md) ·
[Solução de problemas](docs/troubleshooting.md) ·
[FAQ](docs/faq.md) ·
[Segurança](docs/security.md) ·
[Arquitetura](docs/architecture.md)

## Desenvolvimento

```bash
bash install/bootstrap.sh      # ou: composer setup
php artisan serve --port=8770
php artisan test
```

Verifique o ambiente a qualquer momento com
`php artisan rnvsync:doctor`. Requer PHP 8.3 com `pdo_sqlite`. Veja
[CONTRIBUTING.md](CONTRIBUTING.md).

## Apoie o projeto 💛

O RNV Sync é gratuito e open source. Se ele te ajuda, um PIX de
qualquer valor ajuda a manter o projeto vivo. 🙏

<img src="docs/images/pix.png" alt="QR Code PIX" width="220">

Aponte a câmera do app do seu banco, ou use o **copia e cola** e mais
detalhes em **[DOACAO.md](DOACAO.md)**.

## Licença

MIT — veja [LICENSE](LICENSE). O RNV Sync empacota o binário oficial
do rclone **sem modificações**; a licença do rclone está em
[LICENSES/rclone.txt](LICENSES/rclone.txt).

## Créditos

[rclone](https://rclone.org/) · [Laravel](https://laravel.com) ·
[Livewire](https://livewire.laravel.com) ·
[Flux UI](https://fluxui.dev) · [Tailwind CSS](https://tailwindcss.com)
