# ONBOARDING — RNV Sync

> ⚠️ **Snapshot de 2026-05-23, parcialmente superado no MESMO dia.** Mudanças
> posteriores (ver `git log`): (1) **online por padrão** — ao conectar a conta
> todas as pastas são espelhadas como ☁ automaticamente (sem passo de
> "selecionar pastas"); (2) **tuning de fila/sync** — `retry_after` corrigido,
> refresh de placeholders configurável, pastas 100% online não ocupam o worker;
> (3) a **tela de seleção de pastas (`FolderSelection`/`accounts.folders`) foi
> removida**. Trate as menções a `FolderSelection` abaixo como histórico.

> Documento de assunção técnica do projeto. Escrito após leitura integral
> de `SPEC.md`, `docs/`, `.notes/CLAUDE.md`, `README*.md` e de **todo** o
> código em `app/`, `config/`, `routes/`, `database/` e `install/`.
>
> **Importante sobre a "memória institucional":** este repositório **não
> tem** a pasta `xDOCUMENTAÇAO/` que o roteiro de onboarding cita. O papel
> dela aqui é cumprido por `SPEC.md` (58 KB, a especificação original), pela
> pasta `docs/` e por `.notes/CLAUDE.md`. Tratei esses arquivos como o "porquê"
> e cruzei cada um com o código. **Onde divergem, o código é o estado atual** —
> as divergências estão na Seção 8.
>
> Estado verificado em 2026-05-23: suíte Pest **150 testes / 447 asserções,
> 100% verde** em ~4s; PHP 8.3.31 com `pdo_sqlite`; binário rclone empacotado.

---

## 1. Resumo executivo

**RNV Sync** é um cliente OneDrive *self-hosted* para Linux, com cara de app
nativo, acessado pelo navegador em `http://localhost:8770`. É uma aplicação
**Laravel 12 + Livewire 3** que **empacota e orquestra o `rclone`** — a relação
é a do GitHub Desktop com o git: o rclone é o motor de sincronização confiável;
o RNV Sync é a camada de UX e ciclo de vida (login gráfico, sincronização
seletiva, arquivos sob demanda, bandeja do sistema, emblemas no gerenciador de
arquivos, conflitos, tendências).

- **Público:** usuários de Linux que querem a experiência "OneDrive do Windows"
  sem terminal (as quatro personas do `SPEC.md` §3: "refugiado do Windows",
  *self-hoster*, *power user* cansado de TOML, e "TI da família").
- **Maturidade:** funcionalmente em **v1.0** — todas as features v0.1→v0.5 do
  SPEC estão implementadas e cobertas por testes (`docs/acceptance.md`). O que
  falta é validação manual em hardware/contas reais (marcado ⚠ no checklist).
- **Singularidade do projeto:** é **single-user, local-first, zero telemetria**.
  Tudo (SQLite, fila, sessão, cache) roda na máquina do usuário; só o sync sai
  para a Microsoft, via rclone. O painel escuta só em `127.0.0.1`.
- **Próximo passo lógico:** a **hidratação sob demanda** (abrir um placeholder ☁
  baixar o arquivo automaticamente). Hoje no Linux não há "hydrate-on-open" sem
  FUSE; a memória do projeto (`pending-fod-hydration`) registra que a opção A
  (vigiar eventos `open` via inotify) foi escolhida mas **ainda não foi codada**.
  Em segundo lugar: reconciliar a documentação histórica (SPEC ainda fala
  "Cirrus" e descreve só o modelo FUSE — veja Seção 8).

> **Origem do nome:** o projeto nasceu como **"Cirrus"**. `SPEC.md` e
> `.notes/CLAUDE.md` ainda usam "Cirrus", prefixo `cirrus_`, `~/Cirrus/` e
> `config/cirrus.php`. O **código foi renomeado para "RNV Sync"** (`rnvsync_`,
> `~/RnvSync`, `config/rnvsync.php`). É a primeira coisa a ter em mente ao ler
> a especificação.

---

## 2. Stack

| Camada | Tecnologia | Versão (efetiva) | Observações de ciclo de vida |
|---|---|---|---|
| Linguagem | PHP | requer `^8.2`; **8.3.31 instalado** | PHP 8.3 já em **fase de correções de segurança** (suporte ativo encerrou no fim de 2025; segurança até ~fim de 2027). Verificar antes de fixar. |
| Framework | Laravel | `^12.0` | Laravel 12 (lançado fev/2025): correções de bugs até ~ago/2026, segurança até ~fev/2027. |
| Front reativo | Livewire | `^3.0` | Server-driven; sem SPA. |
| Componentes UI | **Flux UI** (`livewire/flux`) | `^2.0` | Botões, inputs, modais, etc. Free tier. |
| WebSocket | **Laravel Reverb** | `^1.0` | Servidor próprio (porta 8771), substitui Pusher. |
| CSS | **Tailwind CSS v4** | `^4.0` (plugin Vite) | Sem `tailwind.config.js`; config v4 no CSS. |
| Bundler | **Vite** | `^7.0` | SPEC dizia Vite 6 — hoje é 7. |
| JS | `laravel-echo` + `pusher-js` | — | Echo conectado ao Reverb. Alpine vem com Livewire. |
| Banco | **SQLite** | 3.x | **WAL + busy_timeout 5s** (ver `config/database.php`) por causa da concorrência (web + fila + scheduler + watcher). |
| Cache / Fila / Sessão | **driver `database`** (SQLite) | — | `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`. **Sem Redis/Memcached** (proibido pelo SPEC). |
| Broadcast | `reverb` | — | `BROADCAST_CONNECTION=reverb`. |
| Testes | **Pest 3** (+ PHPUnit 11) | dev | `tests/Feature`, `tests/Unit`, `tests/Pest.php`. |
| Estilo de código | **Laravel Pint** | dev | **Sem `pint.json`** → usa o preset Laravel padrão. CI roda `pint --test`. |
| Análise estática | — | — | **Não há PHPStan/Larastan.** |
| Motor de sync | **rclone** | `1.67.0` (pinado em `config/rnvsync.php`) | Binário **empacotado** em `rclone/rclone` (~61 MB), nunca o do PATH do usuário. |

**Integração externa única:** Microsoft Graph / OneDrive (Personal, Business,
SharePoint) via OAuth. Sem S3, Stripe, e-mail transacional, NF-e, etc.

**Contexto brasileiro:** não há tratamento de CPF/CNPJ, moeda BRL nem
integração fiscal — não se aplica (é um utilitário de desktop). Locale `pt-BR`
e `en` são primeira-classe (`lang/`). Timezone fica em **UTC** (`config/app.php`);
o SPEC §17 pede exibição na TZ local, mas não há camada de formatação por TZ —
ver Seção 8.

---

## 3. Arquitetura

```
Navegador ──HTTP/WebSocket── Laravel (páginas Livewire · Reverb · fila DB)
                                   │
                         Camada de Serviços (app/Services/*)  ← ÚNICO lugar que
                              │            │                     fala com rclone
                           SQLite     subprocesso rclone ── OneDrive (Graph)
```

- **Organização:** MVC + **camada de serviços** robusta. Regra de ouro
  (`docs/architecture.md`, `.notes/CLAUDE.md`): **só `app/Services/` invoca o
  rclone ou o Graph**. Páginas Livewire e controllers nunca chamam rclone direto.
  Não é DDD nem modular — é "service layer" pragmática, com *value objects*
  (`RcloneResult`, `Bytes`) e `declare(strict_types=1)` nos serviços.
- **Multi-tenant:** não. **Single-user** por design. Um único `User` no painel.
- **Auth:** sessão (guard `web`, driver `session`, provider Eloquent), modelo
  `App\Models\User` na tabela `rnvsync_users`, senha **bcrypt** (cast `hashed`),
  throttle de 5 tentativas/5 min por IP (`Login` Livewire). Reset por CLI
  (`rnvsync:reset-password`). Sem 2FA (o SPEC previa para v0.5; não implementado).
- **API:** não há API REST/GraphQL pública. Há dois endpoints HTTP "crus"
  fora do grupo `auth`: `/requirements` (preflight) e `/sync-state` (bandeja),
  ambos *stateless* (sem sessão/CSRF) e localhost-only. O resto são páginas
  Livewire + o round-trip OAuth.
- **Estratégia de jobs:** fila `database`, **um worker** (`queue:work`). A maior
  parte da pesar (download, "manter online", sync de mudanças, materialização de
  placeholders, warm de cache) é **enfileirada** para nunca travar a UI. Há um
  **scheduler** ativo (`schedule:work`) em `routes/console.php`.
- **Modelo de processos em produção (systemd user services, sem root):**
  `rnv-sync-web` (`serve` :8770), `rnv-sync-queue` (`queue:work --memory=512`),
  `rnv-sync-reverb` (:8771), `rnv-sync-scheduler` (`schedule:work`),
  `rnv-sync-watch` (`rnvsync:watch`, vigia inotify para upload em tempo real).
  Mais o **app de bandeja** (Python/GTK) e a **extensão Nautilus** (Python).

---

## 4. Mapa do projeto

> Para cada módulo, a referência de "intenção" é a seção indicada do `SPEC.md`
> (a memória institucional deste repo). Onde o código divergiu, ver Seção 8.

### `app/Services/` — coração da aplicação (intenção: SPEC §4, §8)

| Arquivo | Papel |
|---|---|
| `Rclone/RcloneRunner` | **Gateway único** para o binário. `run()` (síncrono, com servidor `--rc` para stats ao vivo em transfers), `runBackground()`/`spawn()` (detach com `setsid`, fecha fds herdados p/ não prender a porta do web). Mock disto nos testes. |
| `Rclone/RcloneBinary` | Resolve caminho/`--config` do binário empacotado; `assertAvailable()`. |
| `Rclone/RcloneConfigGenerator` | Gera `rclone.conf` a partir da tabela `accounts` (**escrita atômica via temp+rename, 0600**, tokens decifrados). `syncTokenBack()` p/ contas bundled. |
| `Rclone/RcloneResult` / `JsonLogParser` | Resultado imutável; parse do `--use-json-log` linha a linha. |
| `Graph/OneDriveOAuth` | OAuth in-app (SPEC §8 "Path B"): authorize URL, troca de código, **refresh automático** (janela 10 min), `fetchUser/fetchDrive/fetchQuota`, extrai `tid` do JWT. |
| `Graph/RcloneAuthorize` | **OAuth zero-config** (`rclone authorize "onedrive"`): usa o app público do rclone, sem registro no Entra. **É o caminho padrão hoje** (além do SPEC). |
| `Accounts/AccountsService` | Ciclo de vida da conta: `completeOAuth` / `completeFromToken` (bundled), `refreshQuota`, `listRemote` (lsjson). |
| `Sync/SyncService` | Uma tentativa de **`rclone bisync`** + histórico + detecção de conflito + 429. |
| `Sync/BandwidthScheduler` | Limite de banda efetivo "agora" (janela horária, suporta janela noturna). SPEC F5.2. |
| `Mount/MountService` | Ciclo de vida do **`rclone mount`** FUSE + cálculo do limite de cache. (Modo `mount`, não-padrão.) |
| `Cache/CacheService` | Cache VFS: pin/unpin, free, eviction LRU protegendo pinados. (Modo `mount`.) |
| `Conflicts/ConflictsService` | Detecta conflitos do log do bisync, resolve (local/remote/both/ignore), auto-pausa conta com >10 conflitos. SPEC §F4. |
| `Files/LocalFiles` | **Modelo físico (padrão):** arquivos reais + placeholders 0-byte; `download`, `free` (upload-se-preciso e libera), `materializeCloudPlaceholders`, `status`. **Anti-N+1/OOM:** usa `RecursiveDirectoryIterator` com early-exit em vez de `File::allFiles()`. |
| `Files/PendingOps` / `Files/PathErrors` | Estado "sincronizando…"/"erro" por caminho absoluto, em JSON (lidos também pela extensão Nautilus e pela bandeja). |
| `Settings/SettingsRepository` | Acesso tipado à tabela `rnvsync_settings` (k/v JSON); `storageMode()`, `setupComplete()` (cache 5 min). |
| `Settings/ConfigService` | Export/import de config **sem tokens** (SPEC F5.9). |
| `System/RequirementsService` | Preflight estilo WordPress (PHP, pdo_sqlite, APP_KEY, storage, DB, rclone) com comando de correção por distro. **Nunca toca o banco.** |
| `Update/UpdateService` | **Auto-update** (git): `checkForUpdates` (cacheado), `runUpdate` (dispara `install/update.sh` destacado). Além do SPEC. |

### `app/Jobs/` (fila database)

- `StartSyncJob` — bisync com retry/backoff (4 tentativas, 5s/30s/5min). SPEC F2.
- `SyncChangesJob` (`ShouldBeUnique`) — **sync leve de mudanças** do modo
  `on_demand`: push de arquivos reais (`--min-size 1b --no-traverse`), pull só
  dos arquivos mantidos offline (`--files-from`), e materialização de novos
  placeholders **throttled (1×/30min)**. É onde moram as lições de performance
  dos últimos commits (não estourar memória/queue com pastas de 72k arquivos).
- `DownloadPathJob` / `FreeOnlineJob` (`ShouldBeUnique`) — "manter local" /
  "manter online" no modo físico, em background, com estado ⟳/erro.
- `MaterializePlaceholdersJob` — espelha pasta remota como árvore de placeholders ☁.
- `WarmCacheJob` — baixa caminho pinado para o cache VFS (modo mount).

### `app/Console/Commands/` (12 comandos `rnvsync:*`)

`scheduled-sync` (a cada 15 min) · `mount-supervisor` (1 min, saúde do mount +
sweep de PendingOps) · `capture-usage` (diário, tendências) · `check-updates`
(2×/dia) · `prune-orphan-folders` (diário, desativa pastas sumidas na nuvem) ·
`adopt-local-folders` (5 min, sobe pastas criadas localmente) ·
`discover-remote-folders` (5 min, espelha novas pastas-topo da nuvem) ·
`watch` (daemon inotify, upload em tempo real) · `fs {download|free} {path}`
(chamado pela **extensão Nautilus**) · `nautilus-config` (gera `extension.json`) ·
`doctor` (espelho CLI do preflight) · `reset-password` (interativo).

### `app/Livewire/Pages/` (telas) · `app/Http/` · `app/Models/` · `app/Events/`

- **Livewire:** `Dashboard`, `Setup/Wizard`, `Auth/Login`, `Accounts/{AddAccount,
  FolderSelection, FileBrowser, SyncActivity}`, `ConflictsPage`, `SearchPage`,
  `TrendsPage`, `Settings/SettingsPage`.
- **Controllers (4):** `OAuthController` (easy + in-app), `SyncStateController`
  (bandeja), `ConfigController` (export), `RequirementsController` (preflight).
- **Middleware (3):** `EnsureRequirementsMet` (global, antes do web group),
  `EnsureSetupComplete` (redireciona pro wizard; **isenta AJAX do Livewire** —
  ver memória `livewire-middleware-gotcha`), `SetLocale`.
- **Models (9):** ver Seção 7. **Events (3):** `SyncProgress`, `SyncStatusChanged`,
  `ConflictDetected` — todos `ShouldBroadcast` no canal público `rnvsync`.
- **Logging:** `SecretRedactionProcessor` + tap `RedactSecrets` nos canais
  `rnvsync-app` e `rnvsync-rclone` (daily, 14 dias) — **nenhum token vaza pro log**.

### Fora do `app/`

`install/` (bootstrap, install, update, uninstall, ensure-system-deps, systemd/,
nautilus/, tray/) · `lang/{en,pt-BR}` · `resources/views/livewire/...` ·
`config/rnvsync.php` (defaults do projeto) · `docker/Dockerfile` +
`docker-compose.yml` · `.github/workflows/` (tests, docker-build, release).

---

## 5. Convenções observadas

- **Nomes:** rotas em kebab-case com nomes camelCase (`accounts.folders`);
  tabelas snake_case plural com **prefixo `rnvsync_`**; models PascalCase
  singular; componentes Livewire em `app/Livewire/Pages`; constantes de status
  como `const STATUS_ACTIVE`. Identificadores e comentários **em inglês**;
  strings de UI via `__()` (EN + pt-BR).
- **Serviços vs facades:** serviços são injetados (constructor injection / DI do
  container). Facades (`Http`, `Process`, `File`, `Cache`, `Log`) usadas dentro
  dos serviços. Páginas Livewire recebem serviços por *method injection* em
  `mount()`/ações/`render()`.
- **Eloquent vs SQL cru:** Eloquent em todo lugar. Único `selectRaw` é o
  agregado de tendências (`TrendsPage`). Nenhum `DB::raw`/`whereRaw` com input
  do usuário. `SyncStateController` lê `DB::table('jobs')` (read-only, parse do
  payload) — aceitável.
- **Validação:** **inline nos componentes Livewire** (`$this->validate([...])`)
  e atributos `#[Validate]`. **Não há FormRequests** — coerente com a stack
  Livewire (ver nuance na Seção 8).
- **Resposta:** páginas devolvem views Livewire; sem API Resources. Os 2
  endpoints crus devolvem JSON manual.
- **Models:** **todos têm `$fillable`** e `casts()`; tokens com cast
  `encrypted` e `$hidden`. **Nenhum `boot()` com query.**
- **Padrão de robustez recorrente (vale internalizar):** operações de
  arquivo/rede são **best-effort com `try/catch` que degrada em silêncio** onde
  o efeito é cosmético (stats ao vivo, placeholders), mas **lançam exceção**
  onde há risco de perda de dados (upload antes de apagar local). Escritas
  concorrentes são **atômicas** (temp+rename) e processos longos são
  **destacados** corretamente. Mantenha esse padrão ao editar.

---

## 6. Fluxos principais (ponta a ponta, com classes reais)

1. **Primeira execução / preflight.** Qualquer rota → `EnsureRequirementsMet`
   (global). Se falta algo crítico → `/requirements`
   (`RequirementsController` + `RequirementsService`, sem DB/sessão). OK →
   `EnsureSetupComplete`: sem `User` → `Setup/Wizard` (idioma ao vivo → cria
   `User` bcrypt → grava settings → `Auth::login`).

2. **Conectar conta (padrão, zero-config).** `AddAccount` → `/oauth/easy`
   (`OAuthController::easyStart`) → `RcloneAuthorize::start()` dispara
   `rclone authorize` → página `oauth/waiting` envia o usuário à Microsoft e faz
   *poll* em `/oauth/easy/status` → ao capturar o token,
   `AccountsService::completeFromToken()` cria a `Account` com
   `uses_bundled_client=true` → `RcloneConfigGenerator::regenerate()`. (O caminho
   "Avançado" é `oauth/start`→`callback` com app Entra próprio, via
   `OneDriveOAuth`.)

3. **Escolher pastas e sincronizar.** `FolderSelection` (lista remota cacheada
   45s) → `save()` cria `SyncFolder` com **`sync_mode = 'on_demand'`** →
   `MaterializePlaceholdersJob`→`SyncChangesJob` (encadeados). Placeholders ☁
   aparecem no gerenciador; edições locais sobem via watcher; novos arquivos da
   nuvem viram placeholders. `SyncActivity` mostra histórico e "sincronizar agora".

4. **Manter local / Manter online (Files-on-Demand físico).** `FileBrowser` ou
   `FolderSelection` → `download()`/`free()` marca `PendingOps` (UI vira ⟳) e
   enfileira `DownloadPathJob`/`FreeOnlineJob` → `LocalFiles::download()` (substitui
   placeholder por arquivo real) ou `LocalFiles::free()` (**sobe se houver dado
   real**, depois deixa placeholder 0-byte). Sucesso limpa `PendingOps`; falha
   grava `PathErrors` (UI vira "erro" com tooltip). A **extensão Nautilus** chama
   o mesmo caminho via `rnvsync:fs`.

5. **Bandeja em tempo real.** O app de bandeja faz *poll* em `/sync-state`
   (`SyncStateController`) a cada ~2s: lê stats ao vivo do servidor `--rc` do
   rclone (arquivo `rnvsync-rc.json`), soma `PendingOps`, e cai para nomes de
   pastas com sync enfileirado. Anima o ícone enquanto há transferência.

---

## 7. Pontos críticos

- **Caminho crítico de negócio = integridade dos dados do usuário.** O que NÃO
  pode quebrar: a lógica de placeholder 0-byte em `LocalFiles::free()` /
  `SyncChangesJob` (`--min-size 1b`, `--no-traverse`). Um placeholder vazio
  tratado como arquivo real **sobrescreveria o arquivo na nuvem com nada**. O
  código defende isso (sobe antes de apagar; nunca apaga local se o upload
  falhou), e há testes (`KeepOnlineUploadTest`, `PendingOpsTest`). Mexa aqui com
  extremo cuidado e sempre com teste.
- **Modelos centrais (5):** `Account` (1→N `SyncFolder`, `MountProcess`; token
  cifrado), `SyncFolder` (N→1 `Account`; `sync_mode`, overrides por pasta),
  `SyncHistory` (auditoria; `sweepStale()` cura runs órfãos), `Conflict`
  (pendente/resolvido), `FilePolicy` (pin "always_offline", único por
  `account_id+path`). Secundários: `Setting`, `MountProcess`, `UsageSnapshot`,
  `User`.
- **Sem controllers/models gigantes:** o maior arquivo é `LocalFiles` (322
  linhas). Nenhum >500. Saudável.
- **N+1 / varreduras:** o risco real não é Eloquent e sim **varrer árvores de
  arquivos**. Já mitigado com `RecursiveDirectoryIterator` + early-exit e cache
  curto de status de diretório. **Não regrida** para `File::allFiles()` em
  caminhos de árvore grande (Finder ordena tudo antes de devolver → 500/OOM).
- **Fila com um único worker:** todo trabalho pesado passa por **um**
  `queue:work`. Jobs caros são `ShouldBeUnique` e o `SyncChangesJob` faz
  *throttle* da listagem recursiva — fruto direto do incidente da pasta de 72k
  arquivos (memória `queue-jam-large-folder`). Cuidado ao adicionar jobs longos.
- **Tolerância a falha das filas:** sync tem 4 tentativas com backoff; downloads/
  free têm 2; falha final marca conta `error`/grava `PathErrors`. 429 da
  Microsoft é tratado e re-tentado.
- **Ambiguidades registradas:** (a) `sync_mode` `download_only`/`upload_only`
  existem no SPEC mas **não são usados** no código; (b) a coluna
  `conflicts.local_*`/`remote_*` é preenchida só parcialmente
  (`ConflictsService` cria com path+detected_at). Resolução proposta: tratar
  `on_demand` e `bisync` como os únicos modos vivos e documentar os campos de
  conflito como "best-effort".

---

## 8. Red flags específicas de Laravel e divergências SPEC ↔ código

**Higiene de segurança — em ordem (a maioria está OK):**

- `APP_DEBUG`: default **`false`** em `config/app.php`. `.env.example` traz
  `APP_DEBUG=true`, aceitável para um app local de desenvolvimento, mas o
  instalador deve garantir `false` em produção — **conferir** o `.env` gerado.
- **Mass assignment:** OK — todos os models têm `$fillable`.
- **SQLi:** OK — sem `DB::raw`/`whereRaw` com input do usuário.
- **Rotas sensíveis:** OK — tudo sob `middleware('auth')`, exceto
  `/requirements` e `/sync-state` (intencional: stateless, localhost-only, sem
  segredos) e o round-trip OAuth (precisa ser acessível pós-login).
- **`dd()/dump()/ray()` esquecidos:** nenhum encontrado.
- **`.env` no histórico do git:** **não** (`git log --all -- .env` vazio). Bom.
- **`env()` fora de `config/`:** OK — o código usa `config('rnvsync...')`.
- **Tokens em log:** OK — `SecretRedactionProcessor` redige tudo.
- **Testes tocando banco real:** OK — Pest com SQLite `:memory:`.
- **`down()` destrutivo:** as migrations têm `down()` que dropam tabelas — normal
  para schema novo; nada disfarçado.

**Pontos menores a observar:**

- **Sem FormRequest:** validação inline no Livewire. É idiomático para a stack,
  mas significa que regras de validação ficam espalhadas pelos componentes — ao
  crescer, considere `Livewire\Form` objects (o `.notes/CLAUDE.md` recomenda
  para >2 campos; hoje quase nada usa).
- **Sem PHPStan/Larastan e sem `pint.json`:** a qualidade é mantida por testes +
  Pint default no CI. Não há análise estática de tipos.
- **`DatabaseSeeder`** ainda referencia `'name' => 'Test User'` no `User`, que
  **não é coluna nem `$fillable`** (resquício do skeleton). Inócuo (é
  silenciosamente descartado) e o seeder não entra no fluxo de instalação, mas
  está errado.
- **Timezone UTC:** `config/app.php` usa `UTC`; o SPEC §17 pede exibição na TZ
  local. Hoje datas são gravadas/exibidas em UTC sem camada de conversão por
  locale. Para um app single-user local, baixo impacto, mas é uma divergência.
- **`UpdateService::runUpdate()`** faz `@exec(... &)` *fire-and-forget*. É
  proposital (sobrevive ao restart do próprio web), mas é a única execução de
  shell "solta" do código — trate como sensível.

**Divergências SPEC/docs ↔ código (módulo a módulo) — o mais importante desta seção:**

1. **Branding (global):** SPEC e `.notes/CLAUDE.md` dizem **"Cirrus"**, prefixo
   `cirrus_`, `~/Cirrus/`, `config/cirrus.php`, comando `cirrus:reset-password`.
   **Código:** "RNV Sync", `rnvsync_`, `~/RnvSync`, `config/rnvsync.php`,
   `rnvsync:*`. *O código é o estado atual.*

2. **Modelo de armazenamento (a maior divergência):** o SPEC §9 v0.3.0 descreve
   Files-on-Demand **só** como **mount FUSE + cache VFS**. **O código tem dois
   modos** e o **padrão é `physical`** (`config('rnvsync.storage_mode')`):
   arquivos reais em disco + placeholders 0-byte + emblemas do Nautilus, **sem
   FUSE** (`LocalFiles`, `DownloadPathJob`, `FreeOnlineJob`). O modelo do SPEC
   (`MountService`/`CacheService`) existe e é testado, mas é **opcional/não-padrão**.
   `docs/files-on-demand.md` (posterior ao SPEC) documenta isso e é honesto:
   **não há hydrate-on-open no Linux sem FUSE**.

3. **Modos de sync:** SPEC: `bisync` é o caminho. **Código:** `FolderSelection`
   cria pastas como **`on_demand`** (placeholders + `SyncChangesJob`); o caminho
   `bisync` (`SyncService`/`StartSyncJob`) continua existindo e testado, mas
   **não é o default**. Atenção: o docblock de `FolderSelection` ainda diz "a
   pasta inteira é baixada como arquivos reais" — **comentário desatualizado**;
   o código grava `on_demand`.

4. **OAuth:** SPEC decidiu **só Path B** (in-app, app Entra próprio). **Código**
   adicionou o caminho **zero-config** (`RcloneAuthorize`, `uses_bundled_client`),
   que é o **recomendado/padrão** hoje; Path B virou "Avançado".

5. **Conflitos (v0.4.0):** implementado conforme SPEC, **porém** a detecção
   (`ConflictsService::detectFromLog`) só roda no caminho **bisync**. Como o
   default é `on_demand`, **a pipeline de conflitos fica praticamente dormente**
   no uso normal. A tela e a lógica existem e são testadas.

6. **Distribuição:** SPEC: **Docker é o primário**, nativo secundário. **Realidade
   (README/install):** o **instalador nativo systemd-user é o caminho primário**;
   Docker ainda existe (`docker/Dockerfile`, `docker-compose.yml`) mas é secundário.

7. **Além do SPEC (adições que a especificação não previa):** preflight de
   requisitos, **auto-update** (`UpdateService`), **bandeja do sistema**,
   **extensão Nautilus + emblemas**, **auto-descoberta** de pastas (remotas e
   locais), prune de órfãs, **watcher inotify** em tempo real, e **stats de
   transferência ao vivo** via servidor `--rc` do rclone. O SPEC adiava ícones de
   gerenciador de arquivos para "v1.x"; foram entregues.

---

## 9. Próximas 3 ações recomendadas

1. **Reconciliar a memória institucional com o código.** Atualizar `SPEC.md` e
   `.notes/CLAUDE.md` para "RNV Sync"/`rnvsync_` e documentar que o **modelo
   padrão é `physical`** e o sync padrão é **`on_demand`** — hoje a especificação
   induz ao erro qualquer dev novo. Justificativa: é a divergência de maior risco
   de mau-entendimento (Seção 8, itens 1–3).

2. **Implementar a hidratação sob demanda (open→download).** É o próximo passo
   lógico do produto e já está decidido (memória `pending-fod-hydration`, opção
   A: vigiar eventos `open` via inotify no `rnvsync:watch`). Justificativa:
   fecha a última lacuna de UX frente ao OneDrive do Windows no modo físico.

3. **Fortalecer rede de segurança em torno da segurança-de-dados.** Garantir
   `APP_DEBUG=false` no `.env` instalado, limpar o resíduo de `name` no
   `DatabaseSeeder`, e considerar PHPStan/Larastan focado em `app/Services/Files/`
   e `app/Jobs/` (onde mora o risco de perda de dados). Justificativa: o caminho
   crítico (Seção 7) merece análise estática além dos testes.

---

## Critério de conclusão — autoavaliação

- **Versões/ciclo:** Laravel 12 (`^12.0`, correções até ~ago/2026); PHP 8.3
  (instalado 8.3.31; já em fase de segurança). ✔
- **5 controllers/ações mais críticos:** `OAuthController` (conectar conta),
  `FolderSelection` (escolher/sincronizar), `FileBrowser` (manter local/online),
  `SyncStateController` (bandeja em tempo real), `SettingsPage` (config +
  auto-update). ✔
- **5 models centrais + relations:** `Account` (hasMany `SyncFolder`/`MountProcess`),
  `SyncFolder` (belongsTo `Account`), `SyncHistory`, `Conflict`, `FilePolicy`. ✔
- **Auth:** guard `web` por sessão, provider Eloquent, `User`@`rnvsync_users`,
  bcrypt, throttle 5/5min. ✔
- **Jobs/queues/scheduler:** fila `database`, um `queue:work`; scheduler ativo em
  `routes/console.php` (8 tarefas) via `schedule:work`. ✔
- **3 maiores riscos:** (1) segurança de dados na lógica placeholder/free; (2)
  pipeline de conflitos dormente no modo padrão `on_demand`; (3) deriva
  SPEC↔código (Cirrus/FUSE) que engana devs novos. ✔
- **Histórico por módulo + se o código reflete:** ver Seção 8 (1–7). ✔

---

**Pronto para receber tarefas como especialista neste projeto Laravel.**
