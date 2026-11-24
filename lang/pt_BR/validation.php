<?php

// Mensagens de validação em português (as que o sistema usa).
return [
    'boolean' => 'O campo :attribute precisa ser sim ou não.',
    'email' => 'O campo :attribute precisa ser um e-mail válido.',
    'in' => 'Escolha uma opção válida em :attribute.',
    'integer' => 'O campo :attribute precisa ser um número inteiro.',
    'max' => [
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute pode ter no máximo :max caracteres.',
    ],
    'min' => [
        'numeric' => 'O campo :attribute precisa ser pelo menos :min.',
        'string' => 'O campo :attribute precisa ter pelo menos :min caracteres.',
    ],
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute precisa ser um texto.',
    'unique' => 'Já existe um cadastro com este :attribute.',
    'url' => 'O campo :attribute precisa ser um link válido (começando com http:// ou https://).',
    'attributes' => [
        'email' => 'e-mail',
        'password' => 'senha',
    ],
];
