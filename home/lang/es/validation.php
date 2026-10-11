<?php

/**
 * 校验提示的西班牙语句子。键和 lang/zh-CN/validation.php 一一对应（测试会核对）；
 * 字段名（:attribute）由 ApiMessageTranslator::validation 换成西班牙语。
 * 西班牙语名词有阴阳性，所以句子都写成“El campo «字段名» …”，不让形容词跟着字段名变。
 */
return [
    'accepted' => 'Debes aceptar «:attribute».',
    'alpha' => 'El campo «:attribute» solo puede contener letras.',
    'alpha_dash' => 'El campo «:attribute» solo puede contener letras, números, guiones y guiones bajos.',
    'array' => 'El campo «:attribute» debe ser una lista.',
    'before' => 'El campo «:attribute» debe ser una fecha anterior a :date.',
    'between' => [
        'array' => 'El campo «:attribute» debe tener entre :min y :max elementos.',
        'numeric' => 'El campo «:attribute» debe estar entre :min y :max.',
        'string' => 'El campo «:attribute» debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo «:attribute» debe ser verdadero o falso.',
    'confirmed' => 'La confirmación del campo «:attribute» no coincide.',
    'date' => 'El campo «:attribute» no es una fecha válida.',
    'email' => 'El campo «:attribute» debe ser una dirección de correo válida.',
    'exists' => 'El valor seleccionado en «:attribute» no es válido.',
    'file' => 'El campo «:attribute» debe ser un archivo.',
    'gt' => [
        'numeric' => 'El campo «:attribute» debe ser mayor que :value.',
        'string' => 'El campo «:attribute» debe tener más de :value caracteres.',
    ],
    'gte' => [
        'numeric' => 'El campo «:attribute» debe ser como mínimo :value.',
        'string' => 'El campo «:attribute» debe tener al menos :value caracteres.',
    ],
    'in' => 'El valor seleccionado en «:attribute» no es válido.',
    'integer' => 'El campo «:attribute» debe ser un número entero.',
    'lt' => [
        'numeric' => 'El campo «:attribute» debe ser menor que :value.',
        'string' => 'El campo «:attribute» debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'numeric' => 'El campo «:attribute» debe ser como máximo :value.',
        'string' => 'El campo «:attribute» debe tener como máximo :value caracteres.',
    ],
    'max' => [
        'array' => 'El campo «:attribute» no puede tener más de :max elementos.',
        'file' => 'El campo «:attribute» no puede superar :max KB.',
        'numeric' => 'El campo «:attribute» no puede ser mayor que :max.',
        'string' => 'El campo «:attribute» no puede tener más de :max caracteres.',
    ],
    'mimes' => 'El campo «:attribute» debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo «:attribute» debe tener al menos :min elementos.',
        'file' => 'El campo «:attribute» debe pesar al menos :min KB.',
        'numeric' => 'El campo «:attribute» debe ser como mínimo :min.',
        'string' => 'El campo «:attribute» debe tener al menos :min caracteres.',
    ],
    'numeric' => 'El campo «:attribute» debe ser un número.',
    'prohibited' => 'El campo «:attribute» no se puede modificar.',
    'regex' => 'El formato del campo «:attribute» no es válido.',
    'required' => 'El campo «:attribute» es obligatorio.',
    'required_if' => 'El campo «:attribute» es obligatorio cuando «:other» es :value.',
    'required_with' => 'El campo «:attribute» es obligatorio cuando se indica «:values».',
    'required_without' => 'El campo «:attribute» es obligatorio cuando no se indica «:values».',
    'size' => [
        'array' => 'El campo «:attribute» debe tener exactamente :size elementos.',
        'file' => 'El campo «:attribute» debe pesar :size KB.',
        'numeric' => 'El campo «:attribute» debe ser :size.',
        'string' => 'El campo «:attribute» debe tener exactamente :size caracteres.',
    ],
    'string' => 'El campo «:attribute» debe ser un texto.',
    'unique' => 'El campo «:attribute» ya está en uso.',
    'uploaded' => 'No se pudo subir «:attribute». Comprueba el tamaño del archivo.',
    'password' => [
        'letters' => 'El campo «:attribute» debe contener al menos una letra.',
        'mixed' => 'El campo «:attribute» debe contener mayúsculas y minúsculas.',
        'numbers' => 'El campo «:attribute» debe contener al menos un número.',
        'symbols' => 'El campo «:attribute» debe contener al menos un símbolo.',
        'uncompromised' => 'El campo «:attribute» ha aparecido en una filtración de datos. Elige otro valor.',
    ],
    'attributes' => [],
];
