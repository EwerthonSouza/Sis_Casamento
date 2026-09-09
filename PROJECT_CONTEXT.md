# PROJECT_CONTEXT.md — Meu Evento PRO (sis-casamento)

> como o sistema funciona, sem precisar reexplorar todo o código.
> Repositório GitHub: `EwerthonSouza/Sis_Casamento` (branch `main`).
> Produção: www.meueventopro.com.br (deploy manual via WinSCP, sem CI/CD).

## O que é o sistema

Sistema web para **assessorias de casamento** gerenciarem eventos (casamentos)
de ponta a ponta: checklist de tarefas, lista de convidados com RSVP,
organização de mesas, fornecedores, playlist da cerimônia, mural de
inspirações, equipe da assessoria e relatórios em PDF. Os noivos têm um
painel próprio para acompanhar tudo e interagir (marcar tarefas, comentar,
confirmar presença de convidados).

## Stack técnica

- **Backend:** PHP 8.1 + Apache (`php:8.1-apache`), sem framework — PHP
  procedural puro, um arquivo por página.
- **Banco:** MariaDB 10.4, acesso via **PDO** com prepared statements em
  todo o sistema (`conexao.php`).
- **Frontend:** Bootstrap 5.3 + CSS customizado (`css/estilo.css`, variáveis
  como `--color-primary`, `--radius`). Sem build step (sem Vite/Webpack/npm).
- **PDF:** DOMPDF (`dompdf/dompdf`, via Composer) em `relatorio_pdf.php`.
- **Infra local:** Docker Compose com 3 containers:
  - `casamento_app` (PHP/Apache, porta local **8080**)
  - `casamento_db` (MariaDB, porta local **3308**, root/root)
  - `casamento_phpmyadmin` (porta local **8082**)
- **Sem testes automatizados, sem CI.** Validação é manual (`php -l` +
  teste no navegador).

## Como rodar/testar localmente

```bash
docker-compose up -d --build
docker-compose logs -f app          # acompanhar subida
```
- App: http://localhost:8080 — phpMyAdmin: http://localhost:8082
- Login admin padrão: `admin@meueventopro.com` / `admin123`
- Para rodar comandos PHP dentro do container (lint, queries):
  ```bash
  docker exec casamento_app php -l /var/www/html/<arquivo>.php
  docker exec casamento_db mysql -uroot -proot sistema_eventos -e "..."
  ```
  No Git Bash do Windows, prefixe com `MSYS_NO_PATHCONV=1` quando o comando
  tiver paths com espaço (ex: `img/LOGO MEP NAV.svg`), senão o path é mangled.
- O schema completo (estrutura + dados iniciais) fica em
  `gerenciar/sistema_eventos.sql`, importado automaticamente na 1ª subida do
  container `db`.

## Infraestrutura de produção (servidor)

**Descoberto/confirmado em sessão de debug de 09/09/2026** (bug de fuso
horário nas notificações — ver Armadilhas item 10). Guardar isso aqui porque
não é derivável do código, só olhando o servidor de fato:

- Produção roda numa **Droplet DigitalOcean** (Ubuntu 24.04 LTS,
  `ubuntu-s-1vcpu-1gb-nyc1-01`), acessada via console web da DigitalOcean.
- **Os arquivos `Dockerfile`/`docker-compose.yml` de produção NÃO são os
  mesmos do repositório git.** Eles moram direto no servidor em
  `/var/www/meueventopro/` (compose com serviço único `app`, código PHP
  montado via volume `./app:/var/www/html`) e evoluíram separados do que
  está versionado em `app/Dockerfile` / `app/docker-compose.yml` no repo.
  Isso já causou divergência real (ex: produção rodava `php:8.2-apache`
  sem nenhuma configuração de fuso horário, enquanto o repo já tinha
  `php:8.1-apache` com `TZ` configurado — um nunca refletia o outro).
  **Editar o compose/Dockerfile do repo NÃO afeta produção** — qualquer
  mudança nesses arquivos de infra precisa ser replicada manualmente
  também em `/var/www/meueventopro/` no servidor.
- Nome do container em produção é **`meueventopro_app`** (não
  `casamento_app` como no compose local). Variáveis de ambiente reais:
  `DB_HOST=db`, `DB_NAME=meueventopro`, `DB_USER=user`, `DB_PASS=root`
  (diferente do `sistema_eventos`/`root`/`root` usado localmente).
- **O banco de dados em produção é COMPARTILHADO com outro sistema**
  completamente diferente que roda na mesma droplet: um sistema de
  rádio/transcrição (containers `controle_app`, `controle_db`
  — `mariadb:10.11` —, `controle_phpmyadmin`, `transcricao`). O
  `meueventopro_app` se conecta a esse `controle_db` através da rede
  Docker externa `controle-radio_default` (alias `db`). **Por isso:**
  - Nunca reiniciar, recriar ou mudar configuração *global* do
    `controle_db` (ex: `SET GLOBAL time_zone`, trocar `TZ` do container,
    `docker restart`) — afetaria o outro sistema também.
  - Qualquer ajuste que precise ser "só para o Meu Evento PRO" (como
    fuso horário da conexão) deve ser feito **por sessão**, dentro da
    própria conexão PDO do app (`PDO::MYSQL_ATTR_INIT_COMMAND`), nunca
    globalmente no servidor MySQL.
  - Correções de infra em produção devem sempre mirar **só** o serviço
    `app` (`docker compose build app && docker compose up -d app` dentro
    de `/var/www/meueventopro/`), nunca um `up -d` sem escopo que possa
    tocar em outros serviços da mesma rede.
- O servidor tem só o **plugin `docker compose`** (v2, sem hífen,
  ex: `docker compose build app`) instalado — **não** tem o `docker-compose`
  clássico (v1, com hífen). Usar sempre a forma com espaço.

## Autenticação e papéis (roles)

Login único em `index.php`, que checa duas tabelas:

- **`usuarios`** — equipe da assessoria. Campo `tipo` = `admin` ou
  `assistente`. Senhas com `password_hash()`/bcrypt.
- **`clientes`** — noivos (login por e-mail do casal). Papel `noivos`.

> A tabela legada `administradores` (senha em texto puro) foi **removida**
> do código e do schema nesta sessão de trabalho — não existe mais.

Sessão guarda `$_SESSION['usuario_tipo']` (`admin` | `assistente` | `noivos`)
e `$_SESSION['usuario_id']`. Cada página faz o próprio check de role no topo
(não há middleware central). Timeout de sessão de 30 min
(`sessao_timeout.inc.php` → `verificar_sessao_ativa()`, chamado logo após
`session_start()`).

Proteção CSRF: `verificar_csrf()`/`validar_csrf()` comparam
`$_SESSION['csrf_token']` com o POST ou header `X-CSRF-Token`.

## Modelo de dados (visão geral)

- `usuarios` — equipe (admin/assistente).
- `clientes` — noivos/casal (login, dados de contato). `cpf` é **opcional**
  (nullable — nunca gravar `''`, ver seção Armadilhas abaixo).
- `eventos` — o casamento em si (`cliente_id`, `data_evento`, nome do evento
  etc.). Um cliente pode ter vários eventos ao longo do tempo.
- `checklist` / `checklist_comentarios` / `checklist_modelos` — tarefas do
  evento, comentários (assessoria ↔ noivos), e modelos reutilizáveis de
  checklist.
- `convidados` — lista de convidados por evento; `confirmado`,
  `resposta_rsvp`, `acompanhantes`, `filhos`, `mesa_id` (FK opcional para
  `mesas`).
- `mesas` — mesas do evento (`nome`, `capacidade`, `ordem`).
- `fornecedores` / `fornecedores_evento` — cadastro geral de fornecedores e
  vínculo com um evento específico.
- `referencias_fornecedores` — mural de referências/portfólio de
  fornecedores (página `referencias.php`).
- `inspiracoes_fotos` — fotos do mural de inspirações (`inspiracoes.php`),
  com upload de arquivo físico em `uploads/`.
- `musicas_evento` / `playlist_evento` — playlist sugerida por momento da
  cerimônia.
- `notas_evento` — bloco de notas/alinhamentos com o casal.
- `servicos_assessoria` — serviços que a assessoria oferece.
- `calendario_anotacoes` — anotações no calendário do painel admin.
- `notificacoes_lidas` — controla até quando cada usuário já viu o sino de
  notificações (criada sob demanda por `notificacoes.inc.php` se não
  existir).

**Cascades:** FKs com `ON DELETE CASCADE` existem para `checklist`,
`checklist_comentarios`, `convidados`, `eventos→clientes`,
`inspiracoes_fotos`, `playlist_evento`, `servicos_assessoria`. **`mesas`,
`musicas_evento`, `notas_evento` NÃO têm cascade** — a exclusão manual
dessas três é feita explicitamente dentro de `excluir_evento` em
`painel_admin.php`.

## Mapa de páginas

| Arquivo | Quem acessa | Função |
|---|---|---|
| `index.php` | público | Login único (equipe + noivos) |
| `logout.php` | logado | Encerra sessão |
| `painel_admin.php` | admin | Dashboard geral: lista/cadastra/exclui casamentos, calendário, notificações globais, editar cadastro do casal |
| `gerenciar.php` | admin/assistente | Tela principal de um evento específico: checklist, resumo financeiro, acesso rápido às outras seções |
| `gerenciar_equipe.php` | admin | CRUD da equipe (`usuarios`) |
| `modelos_checklist.php` | admin/assistente | Modelos reutilizáveis de checklist |
| `organizar_mesas.php` | admin/assistente | Drag-and-drop de convidados em mesas |
| `fornecedores_evento.php` | admin/assistente | Fornecedores vinculados a um evento |
| `referencias.php` | admin/assistente | Mural de referências de fornecedores (portfólio) |
| `inspiracoes.php` | admin/assistente/noivos | Mural de inspirações (upload de fotos, favoritar, exclusão restrita a admin) |
| `relatorio_pdf.php` | admin/assistente | Gera PDF do evento (seções escolhidas via `?secoes=`: convidados — com mesa, checklist, fornecedores etc.) via DOMPDF |
| `noivos.php` | noivos | Painel do casal: checklist, playlist, notas, visão geral do evento |
| `confirmar.php` | público (link enviado ao convidado) | Página de RSVP para o convidado confirmar/recusar presença |
| `notificacoes_marcar_lidas.php` | logado | Endpoint AJAX que marca o sino de notificações como lido |
| `notificacoes.inc.php` | (include) | Lógica compartilhada de notificações (usado por `painel_admin.php` e `gerenciar.php`) |
| `sessao_timeout.inc.php` | (include) | Timeout de inatividade de 30 min |
| `modal_editar_modelo.inc.php` | (include) | Modal de edição usado em `modelos_checklist.php` |
| `conexao.php` | (include) | Conexão PDO, lê `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` do ambiente |

## Padrões de UI/CSS estabelecidos

- Mobile-first ajustado incrementalmente: `flex-nowrap` + fonte/padding
  reduzido + `overflow-x:auto` para badges; tabelas viram cards no mobile
  (`d-none d-md-block` para tabela, `d-md-none` para cards empilhados);
  `sticky-md-top` em vez de `sticky-top` fixo.
- Fix global de zoom do iOS Safari: `input, select, textarea { font-size:
  16px !important }` dentro de media query mobile em `css/estilo.css` —
  qualquer novo formulário automaticamente já está protegido, não precisa
  reaplicar por página.
- Logos: `img/LOGO MEP NAV.svg` (navbar) e `img/logo MEP1.svg`.

## Armadilhas conhecidas / decisões importantes já tomadas

1. **`cpf` em `clientes` é `UNIQUE` + nullable.** Nunca gravar `''` quando
   vazio — grava `NULL` (string vazia colide com outra string vazia no
   índice único; `NULL` não colide). Ver `painel_admin.php` no cadastro de
   evento.
2. **Duplicidade de casamento** é checada por e-mail OU cpf (não só cpf),
   porque cpf é opcional e e-mail é sempre preenchido.
3. **E-mail da equipe não pode ser reusado como login do casal** — há um
   guard em `painel_admin.php` que bloqueia isso antes de cadastrar.
4. **Exclusão de evento (`excluir_evento` em `painel_admin.php`)** apaga
   manualmente `mesas`, `musicas_evento`, `notas_evento` (sem cascade no
   banco), além do que já tem cascade; também apaga fisicamente as fotos de
   inspiração órfãs em `uploads/` e remove o cliente se não tiver mais
   nenhum outro evento.
5. **Tabela `administradores` foi removida** (código + schema + banco
   local). Se aparecer referência a ela em algum branch antigo, é resquício
   a limpar, não algo a restaurar.
6. **`checklist_render.php` foi excluído** (estava vazio, 0 bytes, sem
   nenhuma referência no projeto).
7. **`Dockerfile`/`docker-compose.yml` moraram fora do repo git** por um
   tempo — hoje já estão dentro de `app/` (raiz do repo), com paths
   corrigidos. Se um clone novo não subir, checar se esses arquivos ainda
   estão na raiz certa.
8. **Deploy em produção é manual via WinSCP** — não há pipeline. Depois de
   alterar schema localmente, é preciso rodar o `ALTER`/`UPDATE`
   correspondente manualmente no banco online também (fácil esquecer).
9. Extensão PHP `calendar` foi removida como dependência no código (trocado
   `cal_days_in_month()` por `date('t', strtotime(...))`) para não depender
   de rebuild de imagem em produção — mesmo assim o Dockerfile ainda instala
   `calendar`, não custa nada tê-la.
10. **Bug de fuso horário nas notificações/lembretes da agenda (corrigido em
    09/09/2026):** o sino mostrava "há 4h" pra um compromisso que tinha
    acontecido havia só ~30 min. Causa: o container `meueventopro_app` em
    produção estava rodando havia tempo com uma imagem **sem nenhuma
    configuração de fuso horário** (default `UTC` da imagem base), porque o
    deploy manual via WinSCP só copia arquivos PHP — nunca reconstrói a
    imagem Docker, então mudanças no `Dockerfile`/`TZ` nunca chegavam a
    produção de fato. `tempo_relativo()` em `notificacoes.inc.php` faz
    `time() - strtotime($dataMysql)`; com o container em UTC interpretando
    uma hora local (ex: "14:30") como se fosse UTC, o cálculo ficava
    adiantado. **A assessoria opera em Boa Vista/RR — fuso
    `America/Boa_Vista`, UTC-4, sem horário de verão** (não é
    `America/Sao_Paulo`/UTC-3 nem `America/Manaus`; já erramos pra ambos os
    lados nessa sessão antes de confirmar o fuso certo com a hora real do
    usuário). Corrigido com três mudanças: (1) `conexao.php` ganhou
    `PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-04:00'"` (fixa o
    fuso só na sessão desta conexão, sem tocar no `controle_db`
    compartilhado — ver seção Infraestrutura de produção); (2)
    `Dockerfile`/`docker-compose.yml` **de produção**
    (`/var/www/meueventopro/`, não os do repo) ganharam
    `TZ=America/Boa_Vista` + `tzdata` + `date.timezone`; (3) rebuild/recreate
    escopado só ao serviço `app`. O `Dockerfile`/`docker-compose.yml` do
    repo (usados só em dev local) foram alinhados pro mesmo
    `America/Boa_Vista`, pra bater com o fuso real da operação.

## Convenções de código observadas

- Sem PSR/autoload de classes — tudo `require_once` direto.
- Escapar saída sempre com `htmlspecialchars()` (às vezes via helper `h()`).
- Todas as queries usam PDO **prepared statements** — nunca concatenar
  input do usuário direto em SQL.
- Textos e comentários do sistema em **português**; siga esse padrão em
  qualquer código/copy novo.
- Validar com `php -l` dentro do container após qualquer edição.
