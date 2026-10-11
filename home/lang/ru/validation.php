<?php

/**
 * 校验提示的俄语句子。键和 lang/zh-CN/validation.php 一一对应（测试会核对）；
 * 字段名（:attribute）由 ApiMessageTranslator::validation 换成俄语。
 * 俄语名词要变格、形容词要跟着名词的性变，所以句子都写成“Поле «字段名» …”，字段名保持原形。
 */
return [
    'accepted' => 'Поле «:attribute» нужно принять.',
    'alpha' => 'Поле «:attribute» может содержать только буквы.',
    'alpha_dash' => 'Поле «:attribute» может содержать только буквы, цифры, дефисы и подчёркивания.',
    'array' => 'Поле «:attribute» должно быть списком.',
    'before' => 'Поле «:attribute» должно быть датой раньше :date.',
    'between' => [
        'array' => 'Поле «:attribute»: количество элементов должно быть от :min до :max.',
        'numeric' => 'Поле «:attribute» должно быть от :min до :max.',
        'string' => 'Поле «:attribute»: длина должна быть от :min до :max символов.',
    ],
    'boolean' => 'Поле «:attribute» должно иметь значение «да» или «нет».',
    'confirmed' => 'Поле «:attribute» не совпадает с подтверждением.',
    'date' => 'Поле «:attribute» не является корректной датой.',
    'email' => 'Поле «:attribute» должно быть действительным адресом эл. почты.',
    'exists' => 'Выбранное значение поля «:attribute» недопустимо.',
    'file' => 'Поле «:attribute» должно быть файлом.',
    'gt' => [
        'numeric' => 'Поле «:attribute» должно быть больше :value.',
        'string' => 'Поле «:attribute»: длина должна быть больше :value (в символах).',
    ],
    'gte' => [
        'numeric' => 'Поле «:attribute» должно быть не меньше :value.',
        'string' => 'Поле «:attribute»: длина должна быть не меньше :value (в символах).',
    ],
    'in' => 'Выбранное значение поля «:attribute» недопустимо.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'lt' => [
        'numeric' => 'Поле «:attribute» должно быть меньше :value.',
        'string' => 'Поле «:attribute»: длина должна быть меньше :value (в символах).',
    ],
    'lte' => [
        'numeric' => 'Поле «:attribute» должно быть не больше :value.',
        'string' => 'Поле «:attribute»: длина должна быть не больше :value (в символах).',
    ],
    'max' => [
        'array' => 'Поле «:attribute»: элементов не больше :max.',
        'file' => 'Поле «:attribute»: размер файла не больше :max КБ.',
        'numeric' => 'Поле «:attribute» должно быть не больше :max.',
        'string' => 'Поле «:attribute»: длина не больше :max (в символах).',
    ],
    'mimes' => 'Поле «:attribute» должно быть файлом одного из типов: :values.',
    'min' => [
        'array' => 'Поле «:attribute»: элементов не меньше :min.',
        'file' => 'Поле «:attribute»: размер файла не меньше :min КБ.',
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute»: длина не меньше :min (в символах).',
    ],
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'prohibited' => 'Поле «:attribute» нельзя изменить.',
    'regex' => 'Поле «:attribute» имеет неверный формат.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'required_if' => 'Поле «:attribute» обязательно, когда поле «:other» имеет значение :value.',
    'required_with' => 'Поле «:attribute» обязательно, когда заполнено поле «:values».',
    'required_without' => 'Поле «:attribute» обязательно, когда не заполнено поле «:values».',
    'size' => [
        'array' => 'Поле «:attribute»: элементов должно быть ровно :size.',
        'file' => 'Поле «:attribute»: размер файла должен быть :size КБ.',
        'numeric' => 'Поле «:attribute» должно быть равно :size.',
        'string' => 'Поле «:attribute»: длина должна быть ровно :size (в символах).',
    ],
    'string' => 'Поле «:attribute» должно быть текстом.',
    'unique' => 'Такое значение поля «:attribute» уже используется.',
    'uploaded' => 'Не удалось загрузить поле «:attribute». Проверьте размер файла.',
    'password' => [
        'letters' => 'Поле «:attribute» должно содержать хотя бы одну букву.',
        'mixed' => 'Поле «:attribute» должно содержать заглавные и строчные буквы.',
        'numbers' => 'Поле «:attribute» должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле «:attribute» должно содержать хотя бы один символ.',
        'uncompromised' => 'Значение поля «:attribute» встречается в утечках данных. Выберите другое.',
    ],
    'attributes' => [],
];
