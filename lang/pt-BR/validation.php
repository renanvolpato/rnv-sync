<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'email' => 'O campo :attribute deve ser um e-mail válido.',
    'string' => 'O campo :attribute deve ser texto.',
    'min' => [
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'confirmed' => 'A confirmação de :attribute não confere.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'unique' => 'O valor de :attribute já está em uso.',

    'attributes' => [
        'email' => 'e-mail',
        'password' => 'senha',
        'new_password' => 'nova senha',
        'current_password' => 'senha atual',
        'language' => 'idioma',
        'mount_base' => 'caminho de montagem',
    ],
];
