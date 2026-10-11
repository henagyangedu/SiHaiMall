<?php

/**
 * 校验提示的日语句子。键和 lang/zh-CN/validation.php 一一对应（测试会核对）；
 * 字段名（:attribute）由 ApiMessageTranslator::validation 换成日语。
 */
return [
    'accepted' => ':attributeに同意してください。',
    'alpha' => ':attributeは英字のみで入力してください。',
    'alpha_dash' => ':attributeは英数字、ハイフン、アンダースコアのみで入力してください。',
    'array' => ':attributeはリストで指定してください。',
    'before' => ':attributeは:dateより前の日付にしてください。',
    'between' => [
        'array' => ':attributeは:min〜:max件にしてください。',
        'numeric' => ':attributeは:min〜:maxの範囲で入力してください。',
        'string' => ':attributeは:min〜:max文字で入力してください。',
    ],
    'boolean' => ':attributeは「はい」か「いいえ」で指定してください。',
    'confirmed' => ':attributeが確認用の入力と一致しません。',
    'date' => ':attributeは正しい日付ではありません。',
    'email' => ':attributeは正しいメールアドレスを入力してください。',
    'exists' => '選択した:attributeは無効です。',
    'file' => ':attributeはファイルを指定してください。',
    'gt' => [
        'numeric' => ':attributeは:valueより大きい値にしてください。',
        'string' => ':attributeは:value文字より多く入力してください。',
    ],
    'gte' => [
        'numeric' => ':attributeは:value以上にしてください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'in' => '選択した:attributeは無効です。',
    'integer' => ':attributeは整数で入力してください。',
    'lt' => [
        'numeric' => ':attributeは:valueより小さい値にしてください。',
        'string' => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte' => [
        'numeric' => ':attributeは:value以下にしてください。',
        'string' => ':attributeは:value文字以下で入力してください。',
    ],
    'max' => [
        'array' => ':attributeは:max件以下にしてください。',
        'file' => ':attributeは:max KB以下にしてください。',
        'numeric' => ':attributeは:max以下にしてください。',
        'string' => ':attributeは:max文字以下で入力してください。',
    ],
    'mimes' => ':attributeは次の形式のファイルにしてください：:values。',
    'min' => [
        'array' => ':attributeは:min件以上にしてください。',
        'file' => ':attributeは:min KB以上にしてください。',
        'numeric' => ':attributeは:min以上にしてください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'numeric' => ':attributeは数値で入力してください。',
    'prohibited' => ':attributeは変更できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeを入力してください。',
    'required_if' => ':otherが:valueの場合、:attributeを入力してください。',
    'required_with' => ':valuesを指定する場合、:attributeを入力してください。',
    'required_without' => ':valuesを指定しない場合、:attributeを入力してください。',
    'size' => [
        'array' => ':attributeは:size件にしてください。',
        'file' => ':attributeは:size KBにしてください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'string' => ':attributeは文字で入力してください。',
    'unique' => 'この:attributeはすでに使われています。',
    'uploaded' => ':attributeをアップロードできませんでした。ファイルのサイズを確認してください。',
    'password' => [
        'letters' => ':attributeには英字を1文字以上含めてください。',
        'mixed' => ':attributeには大文字と小文字を両方含めてください。',
        'numbers' => ':attributeには数字を1文字以上含めてください。',
        'symbols' => ':attributeには記号を1文字以上含めてください。',
        'uncompromised' => 'この:attributeは流出したデータに含まれています。別のものにしてください。',
    ],
    'attributes' => [],
];
