# PROJECT_CONTEXT.md — Meu Evento PRO (sis-casamento)

> como o sistema funciona, sem precisar reexplorar todo o código.
> Repositório GitHub: `EwerthonSouza/Sis_Casamento` (branch `main`).
> Produção: www.meueventopro.com.br (deploy manual via WinSCP, sem CI/CD).

## O que é o sistema

Sistema web para **assessorias de eventos** gerenciarem eventos de ponta a
ponta: checklist de tarefas, lista de convidados com RSVP, organização de
mesas, fornecedores, playlist da cerimônia, mural de inspirações, equipe da
assessoria e relatórios em PDF. Os noivos/responsáveis têm um painel próprio
para acompanhar tudo e interagir (marcar tarefas, comentar, confirmar
presença de convidados).

Desde a introdução do **multi-módulo** (ver seção própria abaixo), o sistema
não atende só casamentos: a mesma estrutura serve **Casamentos, Aniversários,
Eventos Corporativos e Eventos Acadêmicos/Educacionais**, com rótulos e (onde
fizer sentido) dados isolados por módulo.

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
  `DB_HOST=db`, `DB_NAME=meueventopro`, `DB_USER=user`, `DB_PASS=`
  **(senha rotacionada em 10/09/2026 após incidente de segurança — ver
  Armadilhas item 11. Não é mais `root`. Valor atual só no
  `docker-compose.yml` de produção/`/root/scripts/backup_db.sh` no
  servidor — nunca colar a senha real aqui, é arquivo versionado em git).**
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
- **Bloquear porta publicada de container exige regra na cadeia
  `DOCKER-USER`, mirando a porta e o IP JÁ TRADUZIDOS (destino real do
  DNAT), não a porta externa.** Uma regra em `INPUT`/`DOCKER-USER` filtrando
  a porta *externa* (ex: `--dport 3307`) **não bloqueia nada**, porque o
  Docker já reescreve o destino (porta+IP do container) antes do pacote
  chegar em `DOCKER-USER`/`FORWARD`. O jeito certo:
  `iptables -I DOCKER-USER -p tcp -d <ip_do_container> --dport <porta_real_do_container> -j DROP`
  (descobrir IP/porta reais com `iptables -t nat -L DOCKER -n` ou o log de
  DNAT em `/etc/iptables/rules.v4`). **Sempre testar de fora de verdade**
  (`Test-NetConnection` de outra máquina) depois de qualquer regra de
  firewall em cima de porta Docker — não confiar só em "a regra existe".
- **Backup diário automatizado** rodando desde 10/09/2026:
  `/root/scripts/backup_db.sh` (cron `0 7 * * *` = 03:00 Boa Vista), faz
  `mysqldump` dos dois bancos do `controle_db` compartilhado
  (`sistema_demandas` + `meueventopro`), comprime e guarda 14 dias em
  `/root/backups/db/`. Além disso, **DigitalOcean Automated Daily Backups**
  habilitado na droplet inteira (janela 4h-8h UTC = madrugada de Boa
  Vista, retenção 7 dias). Antes disso **não existia backup nenhum** — ver
  Armadilhas item 11.
- **Há um `nginx` rodando direto no host** (fora do Docker), escutando
  `80`/`443`, atuando como reverse proxy pros domínios
  (`meueventopro.com.br`, `demands.com.br` → containers internos via
  `docker-proxy` nas portas `8080`/`8081`/`8082`). Não documentado antes;
  achado durante auditoria de segurança de 10/09/2026.
- **Existe um MySQL 8.0 nativo no host** (fora do Docker, serviço
  `mysql.service`, `127.0.0.1:3306`/`33060`, pacote `mysql-server-8.0`),
  separado do `controle_db` em container. `root@localhost` usa plugin
  `auth_socket` (autenticação pelo usuário do SO, não por senha — **isso é
  o padrão seguro do Ubuntu/Debian, não é uma falha**, mesmo aparecendo
  como "sem senha" numa consulta a `mysql.user`). Tem uma cópia antiga
  (07/05/2026) de `sistema_demandas` — **irrelevante pra recuperação de
  dados**, é resquício velho, não ajuda com a perda de 25/08-10/09.
- **Acesso SSH direto para o Claude Code**: existe uma chave dedicada
  (`~/.ssh/meueventopro_audit` na máquina local do usuário) adicionada em
  `~/.ssh/authorized_keys` do servidor, usada pra auditorias/manutenção
  direta sem precisar de copy-paste no console web. Revogável a qualquer
  momento removendo essa linha do `authorized_keys` no servidor, sem
  afetar o acesso do usuário.
- **Rodar `apt upgrade` no host reinicia o Docker (e consequentemente
  TODOS os containers)** se o pacote do Docker for atualizado junto —
  isso já causou uma queda momentânea dos dois sistemas (auto-recuperada
  em ~1 min pelas políticas `restart: unless-stopped`/`always`). Não é bug,
  é esperado — mas avisar antes de rodar atualização de sistema em
  horário de pico.

## Auditoria de segurança e hardening (10/09/2026)

Feita depois do incidente do item 11, via acesso SSH direto (não só o
Web Console). Achados e correções:

- **CRÍTICO, corrigido**: SSH aceitava login por **senha** pra `root`
  (`PasswordAuthentication yes` + `PermitRootLogin yes`), e estava sob
  **ataque de força bruta ativo** (44.740+ tentativas registradas,
  dezenas de IPs diferentes, contínuo). Corrigido: `PasswordAuthentication
  no` (fixado em `/etc/ssh/sshd_config.d/50-cloud-init.conf`, que estava
  conflitando e vencendo sobre `60-cloudimg-settings.conf` — cuidado com
  isso em qualquer droplet criada via cloud-init da DigitalOcean) +
  `PermitRootLogin prohibit-password` (só chave). **fail2ban** instalado
  e configurado (jail `sshd`: 4 tentativas erradas em 10 min → ban de 24h)
  como camada extra.
- **Investigado, não era problema real**: MySQL nativo do host "sem
  senha" — na verdade `auth_socket` (ver seção Infraestrutura acima).
- **MÉDIO, decisão consciente de não corrigir por enquanto**: phpMyAdmin
  (porta `8081`) exposto pra internet sem restrição de IP — mantido assim
  porque outro desenvolvedor também precisa acessar e não tem IP fixo.
  Opções levantadas pra quando for revisitar: túnel SSH (recomendado,
  não exige IP fixo), lista de IPs liberados, ou Basic Auth extra na
  frente do phpMyAdmin.
- Aplicadas atualizações de segurança pendentes do sistema operacional.
- Container órfão `controle_app_old` (sobra da correção do item 11)
  removido.

## Autenticação e papéis (roles)

Login único em `index.php`, que checa duas tabelas:

- **`usuarios`** — equipe da assessoria. Campo `tipo` = `admin`, `assistente`
  ou `desenvolvedor` (acesso total a todos os módulos, usado em
  `dev_painel.php` pra liberar módulos/planos por usuário). Senhas com
  `password_hash()`/bcrypt.
- **`clientes`** — noivos/responsável (login por e-mail). Papel `noivos`.

> A tabela legada `administradores` (senha em texto puro) foi **removida**
> do código e do schema nesta sessão de trabalho — não existe mais.

Sessão guarda `$_SESSION['usuario_tipo']` (`admin` | `assistente` |
`desenvolvedor` | `noivos`) e `$_SESSION['usuario_id']`. Cada página faz o
próprio check de role no topo
(não há middleware central). Timeout de sessão de 30 min
(`sessao_timeout.inc.php` → `verificar_sessao_ativa()`, chamado logo após
`session_start()`).

Proteção CSRF: `verificar_csrf()`/`validar_csrf()` comparam
`$_SESSION['csrf_token']` com o POST ou header `X-CSRF-Token`.

## Módulos de evento (Casamentos / Aniversários / Corporativo / Acadêmico)

Depois do login da equipe, `hub_modulos.php` deixa escolher qual tipo de
evento administrar; a escolha fica em `$_SESSION['modulo_ativo']`
(`casamento` | `aniversario` | `corporativo` | `academico`). Um link "Trocar
módulo" na navbar volta pro hub sem deslogar. O portal do cliente
(`noivos.php`) não usa `modulo_ativo` — ele já sabe o módulo pelo
`eventos.tipo_evento` do próprio evento logado.

- **`modulos_evento.inc.php`** é o dicionário central: `labels_modulo_evento()`
  devolve todos os textos que mudam por módulo (nome do módulo, "casal" vs
  "aniversariante" vs "responsável", títulos de seção, mensagens de
  WhatsApp etc.), `MODULOS_EVENTO_VALIDOS` é a whitelist,
  `modulo_evento_valido()`/`modulos_liberados_sessao()` validam a sessão,
  `decoracao_hero_svg()` e `titulo_subtitulo_evento()` cuidam de
  detalhes visuais por módulo (este último trata **casamento como caso
  especial**: só ali os dois nomes do cliente são concatenados com "&"; nos
  demais módulos o 2º nome é tratado como "responsável", vira subtítulo).
- **`eventos.tipo_evento`** (default `casamento`) é a fonte da verdade de a
  qual módulo um evento pertence. Cada página de equipe que abre um evento
  específico (`gerenciar.php`, `convidados.php`, `organizar_mesas.php`,
  `fornecedores_evento.php`, `inspiracoes.php`, `relatorio_pdf.php`,
  `modelos_checklist.php`) faz uma **trava cross-módulo**: busca o
  `tipo_evento` do registro alvo e compara com `$_SESSION['modulo_ativo']`;
  se não bater, trata como "não encontrado"/redireciona pro painel — isso
  vale tanto pra renderizar a página quanto pra qualquer ação POST/AJAX
  nela, então um usuário do módulo Aniversário não consegue nem ver nem
  manipular por URL/POST direto um evento de Casamento.
- **Módulo liberado por usuário**: `usuarios_modulos_liberados` (tabela)
  controla quais módulos cada admin/assistente pode ver no hub — configurado
  pelo desenvolvedor. Sem nenhuma linha pra um usuário, o *fallback* é só
  `casamento` liberado (módulo histórico, pra não tirar acesso de ninguém
  sem querer). **Cuidado ao testar**: inserir uma linha pra QUALQUER módulo
  remove esse fallback — se o teste precisar de dois módulos, insira os dois
  explicitamente.
- **Padrão estabelecido pra dados que não devem ser compartilhados entre
  módulos**: adicionar uma coluna `tipo_evento VARCHAR(20) NOT NULL DEFAULT
  'casamento'` na tabela (default `casamento` preserva o comportamento pra
  dados já existentes) e escopar toda `SELECT`/`INSERT`/`UPDATE`/`DELETE`
  por `$modulo_ativo`. Dois exemplos já migrados assim nesta base:
  `notas_gerais_painel` (Bloco de Notas Geral do `painel_admin.php` — cada
  módulo tem o seu, não é mais uma lista global única) e
  `checklist_modelos` (modelos reutilizáveis de checklist usados no
  "Importar Cronograma Padrão" — os que já existiam ficaram em `casamento`;
  os outros módulos começam com a lista vazia, precisam ser cadastrados do
  zero em `modelos_checklist.php`).

## Modelo de dados (visão geral)

- `usuarios` — equipe (admin/assistente/desenvolvedor).
- `usuarios_modulos_liberados` — quais módulos cada usuário da equipe pode
  ver no hub (ver seção Módulos acima).
- `clientes` — noivos/responsável (login, dados de contato). `cpf` é
  **opcional** (nullable — nunca gravar `''`, ver seção Armadilhas abaixo).
  `nome_secundario` guarda o 2º nome (noivo, no módulo casamento; o
  "responsável" nos demais módulos — ver `titulo_subtitulo_evento()`).
- `eventos` — o evento em si (`cliente_id`, `data_evento`, `tipo_evento`
  etc.). Um cliente pode ter vários eventos ao longo do tempo.
  `foto_casal`/`foto_casal_ativa` controlam a foto exibida no topo do link
  de convite (`confirmar.php`); `foto_casal_pos_x`/`foto_casal_pos_y`
  (0–100, default 50) guardam o enquadramento escolhido arrastando a foto
  no preview (`object-position`/`background-position`), pra não cortar
  sempre centralizado.
- `checklist` / `checklist_comentarios` — tarefas do evento e comentários
  (assessoria ↔ cliente).
- `checklist_modelos` — modelos reutilizáveis de checklist ("Importar
  Cronograma Padrão"), com `tipo_padrao` (`com_recepcao`/`sem_recepcao`,
  eixo independente do módulo) **e** `tipo_evento` (módulo — ver seção
  Módulos acima).
- `notas_gerais_painel` — Bloco de Notas Geral do `painel_admin.php`, sem
  `evento_id` (não é do cliente) mas **com** `tipo_evento` (por módulo).
- `convidados` — lista de convidados por evento; `nome` guarda o **nome
  completo já concatenado** (primeiro nome + sobrenome, se houver);
  `sobrenome` guarda só o sobrenome separado, usado pra detectar nome
  duplicado (ver Armadilhas) e pra repopular corretamente os dois campos no
  modal de edição — nunca reconcatenar `nome` sem antes tirar o sufixo
  `sobrenome` dele. Também: `confirmado`, `resposta_rsvp`, `mesa_id` (FK
  opcional pra `mesas`), `convidado_principal_id` (não-nulo = é
  acompanhante de outro convidado, não aparece como linha própria na
  listagem), `token_convite` (link de RSVP individual).
- `mesas` — mesas do evento (`nome`, `capacidade`, `ordem`, `pos_x`/`pos_y`
  — posição no Mapa de Mesas arrastável, `tamanho_mapa` — escala do chip
  circular no mapa, 0.5–2.5, default 1).
- `mapa_elementos` — elementos livres do Mapa de Mesas (Palco, Entrada):
  `pos_x`/`pos_y`, `largura`/`altura` (retângulos como o Palco),
  `rotacao`, `escala` (elementos sem largura/altura próprias, como a
  Entrada, redimensionam por escala). A entrada é criada automaticamente
  (`tipo='entrada'`) na primeira vez que o mapa é aberto pro evento.
- `fornecedores` / `fornecedores_evento` — cadastro geral de fornecedores e
  vínculo com um evento específico (valor previsto, `valor_pago`,
  categoria, prazo). `fornecedores_pagamentos` guarda o histórico
  individual de cada pagamento (valor, data, comprovante).
- `referencias_fornecedores` — mural de referências/portfólio de
  fornecedores (página `referencias.php`).
- `inspiracoes_fotos` — fotos do mural de inspirações (`inspiracoes.php`),
  com upload de arquivo físico em `uploads/`.
- `musicas_evento` / `playlist_evento` — playlist sugerida por momento da
  cerimônia (a lista de "momentos" é definida por módulo em
  `modulos_evento.inc.php`).
- `notas_evento` — bloco de notas/alinhamentos com o cliente (por evento;
  diferente do `notas_gerais_painel`, que é por módulo e não tem dono).
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
| `hub_modulos.php` | admin/assistente | Escolhe o módulo ativo da sessão (Casamentos/Aniversários/Corporativo/Acadêmico) |
| `painel_admin.php` | admin/assistente | Dashboard do módulo ativo: lista/cadastra/exclui eventos, calendário, notificações, Bloco de Notas Geral do módulo, editar cadastro do cliente |
| `gerenciar.php` | admin/assistente | Tela principal de um evento específico: checklist, resumo financeiro, acesso rápido às outras seções |
| `convidados.php` | admin/assistente/noivos | Tela principal de convidados de um evento: criar/editar convite, acompanhantes, WhatsApp/link de RSVP |
| `gerenciar_equipe.php` | admin | CRUD da equipe (`usuarios`) |
| `modelos_checklist.php` | admin/assistente | Modelos reutilizáveis de checklist (por módulo) |
| `organizar_mesas.php` | admin/assistente/noivos | Mapa de mesas arrastável (mesas, palco, entrada) + drag-and-drop de convidados em mesas |
| `fornecedores_evento.php` | admin/assistente | Fornecedores vinculados a um evento (valor, categoria, prazo, histórico de pagamentos com comprovante) |
| `referencias.php` | admin/assistente | Mural de referências de fornecedores (portfólio) |
| `inspiracoes.php` | admin/assistente/noivos | Mural de inspirações (upload de fotos, favoritar, exclusão restrita a admin) |
| `relatorio_pdf.php` | admin/assistente | Gera PDF do evento (seções escolhidas via `?secoes=`: convidados — com mesa, checklist, fornecedores etc.) via DOMPDF |
| `noivos.php` | noivos | Painel do cliente: checklist, playlist, convidados, notas, personalização do convite, visão geral do evento |
| `confirmar.php` | público (link enviado ao convidado) | Página de RSVP para o convidado confirmar/recusar presença — por token individual ou busca por nome (modo geral) |
| `notificacoes_marcar_lidas.php` | logado | Endpoint AJAX que marca o sino de notificações como lido |
| `notificacoes.inc.php` | (include) | Lógica compartilhada de notificações (usado por `painel_admin.php` e `gerenciar.php`) |
| `sessao_timeout.inc.php` | (include) | Timeout de inatividade de 30 min |
| `modal_editar_modelo.inc.php` | (include) | Modal de edição usado em `modelos_checklist.php` |
| `modulos_evento.inc.php` | (include) | Dicionário central de rótulos/labels por módulo (ver seção Módulos acima) |
| `conexao.php` | (include) | Conexão PDO + todas as funções `garantir_*()` de auto-migração idempotente (ver Convenções) |

> **`gerenciar.php` tem um modal "Criar Convite"/"Editar Convidado" órfão**
> (handlers `adicionar_convidado_admin`/`editar_convidado` continuam no
> código, com toda a lógica de sobrenome/duplicidade aplicada por
> consistência) mas **sem nenhum botão que o abra** — a aba Convidados virou
> link direto pra `convidados.php` numa sessão anterior e o modal antigo não
> foi removido. Não é alcançável pela interface hoje; não usar como
> referência de fluxo ativo.

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
11. **Incidente de segurança: banco de produção apagado por ataque
    automatizado (10/09/2026), recuperado só até 25/08/2026.** O
    `controle_db` compartilhado (ver seção Infraestrutura) tinha a porta
    `3307` exposta pra internet inteira (`0.0.0.0`, sem firewall) com
    senha `root`/`root` — um bot varredor de internet achou, apagou os
    bancos e deixou uma nota de resgate (`RECOVER_YOUR_DATA`, pedindo
    Bitcoin — **golpe automatizado, nunca pago, dado não volta assim**).
    Recuperação: só existia **um** snapshot de droplet inteira na
    DigitalOcean, de 25/08/2026 (criado por outro motivo, antes de um
    deploy do sistema vizinho) — **qualquer cadastro feito entre 25/08 e
    10/09 foi perdido de vez**, sem forma de recuperar (sem binlog, sem
    outro backup). Processo usado: criar droplet temporária a partir do
    snapshot → `mysqldump` de lá → `scp` pra produção → importar. Depois
    da restauração, apareceram **tabelas e colunas faltando** no Meu
    Evento PRO (schema mais novo que o snapshot de 25/08):
    `convidados.token_convite`, `convidados.convidado_principal_id`,
    `eventos.modo_confirmacao`, `eventos.mensagem_convite`,
    `eventos.cor_btn_sim`, `eventos.cor_btn_nao`, `mesas.pos_x`,
    `mesas.pos_y` — todas recriadas via `ALTER TABLE` manual comparando
    com `gerenciar/sistema_eventos.sql`. **Achado importante sobre o
    padrão de auto-migração** (ver seção Arquitetura/`conexao.php`): os
    marcadores `uploads/.schema_ok_<chave>` **sobrevivem a uma restauração
    de banco** (são arquivo, não ficam no banco) — depois de restaurar um
    banco mais antigo, o código "acha" que já verificou/criou uma
    tabela/coluna e pula a checagem, mesmo com ela faltando de verdade.
    **Sempre que um banco for restaurado de um backup, apagar todos os
    `uploads/.schema_ok_*` antes de considerar a restauração completa** —
    isso força cada página a re-rodar sua auto-migração e recriar
    sozinha o que estiver faltando (foi assim que se achou
    `documentos_evento`, tabela sequer presente no `schema.sql`, criada
    só via auto-migração em `gerenciar.php`/`noivos.php`). Correções
    aplicadas depois: senha do MySQL (`root` e o usuário `user` usado por
    `DB_USER`) rotacionadas, porta `3307` bloqueada via `iptables` na
    cadeia `DOCKER-USER` (ver Infraestrutura) de forma persistente, backup
    diário automatizado configurado (banco + droplet inteira — ver
    Infraestrutura). **Nunca mais deixar porta de banco publicada sem
    firewall, mesmo que "seja só pra debug".**
12. **Nome de convidado duplicado — comparar o nome FINAL, não só "tem
    sobrenome ou não".** A checagem em `convidado_nome_duplicado()`
    (duplicada em `convidados.php`/`gerenciar.php`/`noivos.php`/
    `organizar_mesas.php`) já passou por uma versão com um bug real: a
    primeira implementação só bloqueava quando o convidado NOVO estava sem
    sobrenome, então dar *qualquer* sobrenome (mesmo repetido) escapava da
    checagem — "Rick" + "Bruno" cadastrado três vezes com telefones
    diferentes passava direto. A versão corrigida concatena nome+sobrenome
    (`$nome_completo`) e compara isso contra o `nome` de **todo mundo** no
    evento (`LOWER(TRIM(nome)) = LOWER(TRIM(?))`, mesmo padrão usado pela
    busca por nome do RSVP em `confirmar.php`) — só um sobrenome que resulte
    num nome realmente diferente resolve a ambiguidade. Ao alterar essa
    lógica de novo, testar exatamente esse caso (mesmo nome+sobrenome
    repetido, telefones diferentes) antes de considerar corrigido.
13. **Telefone e valores monetários têm máscara só em JS, sem biblioteca
    compartilhada.** `formatarTelefoneBr()` (formata como `(DD) 9 XXXX-XXXX`
    pro celular, com o "9" separado, e `(DD) XXXX-XXXX` pro fixo) está
    duplicada em `convidados.php`/`gerenciar.php`/`noivos.php`/
    `organizar_mesas.php` — cada página é standalone, sem include JS
    compartilhado. Só formata como BR até 11 dígitos; acima disso (número
    internacional/DDI) devolve os dígitos como estão, sem tentar impor
    DDD/parênteses. A máscara de moeda BR (formata enquanto digita, dígitos
    viram centavos primeiro — `1500` → `R$ 15,00`) fica só em
    `fornecedores_evento.php`, mesma lógica sem biblioteca.
14. **Padrão pra ajustar enquadramento de imagem (arrastar pra recortar)**:
    a foto do casal no convite (`eventos.foto_casal_pos_x/y`) usa um `<div>`
    com `background-image`/`background-position` arrastável (não um
    `<img>` com `object-position`, exceto no HTML final do convidado em
    `confirmar.php`, que já não precisa de interação). Ver
    `convidados.php`/`noivos.php` pra reaproveitar esse padrão em qualquer
    upload de imagem futuro que precise de recorte manual.

## Convenções de código observadas

- Sem PSR/autoload de classes — tudo `require_once` direto.
- Escapar saída sempre com `htmlspecialchars()` (às vezes via helper `h()`).
- Todas as queries usam PDO **prepared statements** — nunca concatenar
  input do usuário direto em SQL.
- Textos e comentários do sistema em **português**; siga esse padrão em
  qualquer código/copy novo.
- Validar com `php -l` dentro do container após qualquer edição.
- **Sem módulo JS/PHP compartilhado entre páginas** (cada página é
  standalone) — funções que servem várias telas (`convidado_telefone_duplicado()`,
  `convidado_nome_duplicado()`, `nome_convidado_sem_sobrenome()`,
  `formatarTelefoneBr()`, `sincronizar_acompanhantes()`) ficam **duplicadas
  literalmente** em `convidados.php`/`gerenciar.php`/`noivos.php`/
  `organizar_mesas.php`. Ao corrigir um bug numa dessas funções, procurar e
  corrigir a mesma função nos outros 3 arquivos — não existe um único ponto
  de verdade. Exceções que viram função de fato compartilhada só quando
  usadas em ≥2 páginas de propósitos bem diferentes (não guest-management):
  `garantir_*()` de migração ficam em `conexao.php`; helpers de módulo
  ficam em `modulos_evento.inc.php`.
- **Migração de schema é sempre idempotente e auto-executada**: cada
  coluna/tabela nova ganha uma função `garantir_coluna_x()`/
  `garantir_tabela_x()` (em `conexao.php` quando é usada por várias
  páginas, ou inline na própria página quando é local) que faz
  `try { SELECT coluna } catch { ALTER TABLE }`, guardada por um marcador
  em disco (`schema_ja_verificado()`/`marcar_schema_verificado()`) pra não
  bater no banco em toda requisição. **Uma tabela/coluna adicionada a um
  bloco de migração que já rodou antes (marcador já gravado) nunca chega a
  ser criada** — precisa de um marcador NOVO e próprio pra essa coluna
  específica (visto várias vezes nesta sessão: `coluna_sobrenome_convidado`,
  `coluna_tipo_evento_checklist_modelos`, `convite_foto_posicao_v1`,
  `organizar_mesas_tamanho_v1`).
