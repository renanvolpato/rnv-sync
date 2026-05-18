<?php

return [
    'add_account' => 'Adicionar conta',
    'add_subtitle' => 'Conecte uma conta OneDrive ao RNV Sync.',
    'login_with_microsoft' => 'Entrar com a Microsoft',
    'oauth_redirect_note' => 'Você será redirecionado à Microsoft para autorizar o acesso. O RNV Sync nunca vê sua senha da Microsoft.',
    'accounts' => 'Contas',
    'provider_personal_desc' => 'Uma conta Microsoft pessoal (outlook.com, hotmail.com, live.com).',
    'provider_business_desc' => 'Uma conta corporativa ou acadêmica do Microsoft 365.',
    'provider_sharepoint_desc' => 'Uma biblioteca de documentos do SharePoint.',
    'sharepoint_url' => 'URL da biblioteca de documentos do SharePoint',
    'sharepoint_url_hint' => 'ex.: https://contoso.sharepoint.com/sites/Equipe',
    'coming_later' => 'Disponível em uma versão futura',
    'added_success' => 'Conta ":name" conectada com sucesso.',

    'status_active' => 'Ativa',
    'status_disconnected' => 'Desconectada',
    'status_error' => 'Erro',

    'browse_files' => 'Ver arquivos',
    'empty_folder' => 'Esta pasta está vazia.',
    'col_name' => 'Nome',
    'col_size' => 'Tamanho',
    'read_only_note' => 'A listagem de arquivos é somente leitura nesta versão. A sincronização chega na v0.2.0.',

    // OAuth fácil (sem configuração)
    'easy_hint' => 'Conecte sua conta Microsoft em um clique. Sem registrar app, sem client ID — é só entrar.',
    'no_registration_note' => 'Usa o cliente OAuth do rclone empacotado. Nada para configurar.',
    'connecting' => 'Conectando à Microsoft',
    'connecting_hint' => 'Uma janela de login da Microsoft deve abrir. Entre e autorize o acesso.',
    'open_microsoft' => 'Abrir login da Microsoft',
    'popup_blocked' => 'A janela não abriu?',
    'open_link' => 'Abrir aqui',
    'waiting_auth' => 'Aguardando você concluir o login…',

    // Avançado (app próprio no Entra)
    'advanced_toggle' => 'Avançado (usar meu próprio app da Microsoft)',
    'advanced_hint' => 'Para usuários avançados ou OneDrive Business/SharePoint: registre seu app no Microsoft Entra e defina ONEDRIVE_CLIENT_ID/SECRET. Veja docs/oauth.md.',
    'advanced_oauth_note' => 'Você será redirecionado à Microsoft. O redirect URI precisa bater com o registro do seu app.',
];
