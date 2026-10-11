<?php

/**
 * 校验提示的德语句子。键和 lang/zh-CN/validation.php 一一对应（测试会核对）；
 * 字段名（:attribute）由 ApiMessageTranslator::validation 换成德语。
 */
return [
    'accepted' => ':attribute muss akzeptiert werden.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Ziffern, Bindestriche und Unterstriche enthalten.',
    'array' => ':attribute muss eine Liste sein.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Einträge enthalten.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss wahr oder falsch sein.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'date' => ':attribute ist kein gültiges Datum.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Die Auswahl für :attribute ist ungültig.',
    'file' => ':attribute muss eine Datei sein.',
    'gt' => [
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'numeric' => ':attribute muss mindestens :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'in' => 'Die Auswahl für :attribute ist ungültig.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'lt' => [
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'numeric' => ':attribute darf höchstens :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'max' => [
        'array' => ':attribute darf höchstens :max Einträge enthalten.',
        'file' => ':attribute darf nicht größer als :max KB sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'mimes' => ':attribute muss eine Datei dieses Typs sein: :values.',
    'min' => [
        'array' => ':attribute muss mindestens :min Einträge enthalten.',
        'file' => ':attribute muss mindestens :min KB groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'numeric' => ':attribute muss eine Zahl sein.',
    'prohibited' => ':attribute darf nicht geändert werden.',
    'regex' => ':attribute hat ein ungültiges Format.',
    'required' => ':attribute ist erforderlich.',
    'required_if' => ':attribute ist erforderlich, wenn :other :value ist.',
    'required_with' => ':attribute ist erforderlich, wenn :values angegeben ist.',
    'required_without' => ':attribute ist erforderlich, wenn :values nicht angegeben ist.',
    'size' => [
        'array' => ':attribute muss genau :size Einträge enthalten.',
        'file' => ':attribute muss :size KB groß sein.',
        'numeric' => ':attribute muss :size sein.',
        'string' => ':attribute muss genau :size Zeichen lang sein.',
    ],
    'string' => ':attribute muss ein Text sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden. Bitte prüfe die Dateigröße.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss Groß- und Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Ziffer enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute ist in einem Datenleck aufgetaucht. Bitte wähle ein anderes.',
    ],
    'attributes' => [],
];
