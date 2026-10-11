<?php

/**
 * 校验提示的法语句子。键和 lang/zh-CN/validation.php 一一对应（测试会核对）；
 * 字段名（:attribute）由 ApiMessageTranslator::validation 换成法语。
 * 法语名词有阴阳性，所以句子都写成“Le champ « 字段名 » …”，不让形容词跟着字段名变。
 */
return [
    'accepted' => 'Le champ « :attribute » doit être accepté.',
    'alpha' => 'Le champ « :attribute » ne peut contenir que des lettres.',
    'alpha_dash' => 'Le champ « :attribute » ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'array' => 'Le champ « :attribute » doit être une liste.',
    'before' => 'Le champ « :attribute » doit être une date antérieure au :date.',
    'between' => [
        'array' => 'Le champ « :attribute » doit contenir entre :min et :max éléments.',
        'numeric' => 'Le champ « :attribute » doit être compris entre :min et :max.',
        'string' => 'Le champ « :attribute » doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ « :attribute » doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ « :attribute » ne correspond pas.',
    'date' => 'Le champ « :attribute » n’est pas une date valide.',
    'email' => 'Le champ « :attribute » doit être une adresse e-mail valide.',
    'exists' => 'La valeur choisie pour « :attribute » n’est pas valide.',
    'file' => 'Le champ « :attribute » doit être un fichier.',
    'gt' => [
        'numeric' => 'Le champ « :attribute » doit être supérieur à :value.',
        'string' => 'Le champ « :attribute » doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'numeric' => 'Le champ « :attribute » doit être au moins égal à :value.',
        'string' => 'Le champ « :attribute » doit contenir au moins :value caractères.',
    ],
    'in' => 'La valeur choisie pour « :attribute » n’est pas valide.',
    'integer' => 'Le champ « :attribute » doit être un nombre entier.',
    'lt' => [
        'numeric' => 'Le champ « :attribute » doit être inférieur à :value.',
        'string' => 'Le champ « :attribute » doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'numeric' => 'Le champ « :attribute » ne doit pas dépasser :value.',
        'string' => 'Le champ « :attribute » ne doit pas dépasser :value caractères.',
    ],
    'max' => [
        'array' => 'Le champ « :attribute » ne peut pas contenir plus de :max éléments.',
        'file' => 'Le champ « :attribute » ne peut pas dépasser :max Ko.',
        'numeric' => 'Le champ « :attribute » ne peut pas être supérieur à :max.',
        'string' => 'Le champ « :attribute » ne peut pas dépasser :max caractères.',
    ],
    'mimes' => 'Le champ « :attribute » doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ « :attribute » doit contenir au moins :min éléments.',
        'file' => 'Le champ « :attribute » doit faire au moins :min Ko.',
        'numeric' => 'Le champ « :attribute » doit être au moins égal à :min.',
        'string' => 'Le champ « :attribute » doit contenir au moins :min caractères.',
    ],
    'numeric' => 'Le champ « :attribute » doit être un nombre.',
    'prohibited' => 'Le champ « :attribute » ne peut pas être modifié.',
    'regex' => 'Le format du champ « :attribute » n’est pas valide.',
    'required' => 'Le champ « :attribute » est obligatoire.',
    'required_if' => 'Le champ « :attribute » est obligatoire lorsque « :other » vaut :value.',
    'required_with' => 'Le champ « :attribute » est obligatoire lorsque « :values » est renseigné.',
    'required_without' => 'Le champ « :attribute » est obligatoire lorsque « :values » n’est pas renseigné.',
    'size' => [
        'array' => 'Le champ « :attribute » doit contenir exactement :size éléments.',
        'file' => 'Le champ « :attribute » doit faire :size Ko.',
        'numeric' => 'Le champ « :attribute » doit être égal à :size.',
        'string' => 'Le champ « :attribute » doit contenir exactement :size caractères.',
    ],
    'string' => 'Le champ « :attribute » doit être un texte.',
    'unique' => 'La valeur du champ « :attribute » est déjà utilisée.',
    'uploaded' => 'Le champ « :attribute » n’a pas pu être envoyé. Vérifiez la taille du fichier.',
    'password' => [
        'letters' => 'Le champ « :attribute » doit contenir au moins une lettre.',
        'mixed' => 'Le champ « :attribute » doit contenir des majuscules et des minuscules.',
        'numbers' => 'Le champ « :attribute » doit contenir au moins un chiffre.',
        'symbols' => 'Le champ « :attribute » doit contenir au moins un symbole.',
        'uncompromised' => 'La valeur du champ « :attribute » est apparue dans une fuite de données. Choisissez-en une autre.',
    ],
    'attributes' => [],
];
