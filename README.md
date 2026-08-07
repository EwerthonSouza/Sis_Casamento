# Meu Evento PRO — Sistema de Gestão de Casamentos

Sistema web em PHP para assessorias de casamento gerenciarem eventos, checklist,
convidados, fornecedores, mesas, playlist e mural de inspirações — com um
painel separado para os noivos acompanharem tudo.

## Pré-requisitos

- [Docker](https://www.docker.com/products/docker-desktop/) instalado e rodando
  (o Docker Desktop já vem com o Docker Compose incluso).
- Git.

Não é preciso instalar PHP, Composer ou MySQL/MariaDB na sua máquina — tudo
roda dentro dos containers.

## Como rodar localmente

1. **Clone o repositório:**
   ```bash
   git clone https://github.com/EwerthonSouza/Sis_Casamento.git
   cd Sis_Casamento
   ```

2. **Suba os containers:**
   ```bash
   docker-compose up -d --build
   ```
   Na primeira vez isso demora alguns minutos: baixa as imagens do PHP, MariaDB
   e phpMyAdmin, instala as extensões do PHP, roda `composer install` e importa
   o banco de dados automaticamente a partir de `gerenciar/sistema_eventos.sql`.

3. **Acompanhe os logs (opcional)**, até ver o Apache subir:
   ```bash
   docker-compose logs -f app
   ```

4. **Acesse o sistema:**
   - Aplicação: http://localhost:8080
   - phpMyAdmin: http://localhost:8082 (usuário `root`, senha `root`)

## Login

O banco já vem com um usuário administrador pronto para o primeiro acesso:

- **E-mail:** `admin@meueventopro.com`
- **Senha:** `admin123`

> Recomenda-se trocar essa senha assim que possível (não há tela de troca de
> senha própria ainda — pode ser feito direto pelo phpMyAdmin, gerando um novo
> hash com `password_hash()`, ou pela tela "Equipe" já logado como admin).

Também existe uma conta de exemplo do tipo "assistente" (`teste@gmail.com`),
mas a senha dela não é conhecida — é só um registro de exemplo no banco.

## Parar / reiniciar

```bash
docker-compose down          # para os containers, mantém os dados do banco
docker-compose up -d         # sobe de novo sem rebuildar
docker-compose down -v       # para E APAGA os dados do banco (cuidado!)
```

## Estrutura do projeto

- `Dockerfile` / `docker-compose.yml` — definição dos containers (app PHP/Apache,
  MariaDB e phpMyAdmin).
- `gerenciar/sistema_eventos.sql` — schema + dados iniciais do banco, importado
  automaticamente na primeira subida do container `db`.
- `uploads/` — fotos enviadas pelo Mural de Inspirações (montado como volume,
  então os arquivos enviados persistem no seu disco).
- `img/` — logos e imagens estáticas do sistema.
- `css/estilo.css` — estilos compartilhados por todas as páginas.

## Portas usadas

| Serviço     | Porta local |
|-------------|-------------|
| Aplicação   | 8080        |
| phpMyAdmin  | 8082        |
| MariaDB     | 3308        |

Se alguma dessas portas já estiver em uso na sua máquina, ajuste o mapeamento
em `docker-compose.yml` (ex: `"8081:80"`) antes de subir.
