# Comunidade SER — Sistema de Automação

Sistema centralizado para gestão de membros, disparos de mensagens (WhatsApp e E-mail) e automação de aniversariantes para a Comunidade SER.

## Funcionalidades
- **Múltiplas Instâncias de WhatsApp**: Rotação entre instâncias da Evolution API para evitar banimentos e melhorar a carga de envios.
- **Disparo em Massa**: Painel administrativo para envio de mensagens com seleção manual de instância.
- **Automação de Aniversariantes**: Script diário que envia mensagens de parabéns via WhatsApp e E-mail.
- **Área de Amigos**: Gestão de cadastros e check-in via QR Code para eventos.

## Configuração
O sistema utiliza um arquivo `.env` para centralizar as credenciais:
- `DIRECTUS_TOKEN`: Acesso ao CMS.
- `SER_EVO_INSTANCES`: Lista de instâncias (ex: `Instancia1,Instancia2`).
- `SER_EVO_KEYS`: Chaves de API correspondentes.
- `GMAIL_PASS`: Senha de aplicativo para envios de e-mail.

## Contribuidores
- **Daniel Mota** — Arquiteto e Desenvolvedor
- **Gemini CLI** — Engenheiro de IA para Desenvolvimento e Automação
