<?php

namespace App\Support;

/**
 * 西、法、德、日、俄五种语言的内置文字：通知模板（MessageScenes 的默认模板）、协议的默认标题、邮箱验证码邮件。
 * 中文和英文在各自原来的地方（MessageScenes::ALL、ProtocolService::TITLES、VerifyCodeMail）；这里没有的语言用英文。
 * 带地区的语言编码按前半段找（es-MX 用 es）。新增通知场景或协议时，这里每种语言都要补，测试会核对。
 */
final class LocalizedTexts
{
    public const LANGS = ['es', 'fr', 'de', 'ja', 'ru'];

    /** 通知模板：语言 => 场景 => [标题, 正文]。变量要和 MessageScenes 里英文模板的一样 */
    public const SCENES = [
        'es' => [
            'order_paid' => ['[{site_name}] Pago recibido del pedido {order_no}', "Hola, {nickname}:\n\nHemos recibido tu pago de {pay_amount} {currency} del pedido {order_no}. Lo enviaremos lo antes posible.\n\n¡Gracias por tu compra!"],
            'order_shipped' => ['[{site_name}] Tu pedido {order_no} se ha enviado', "Hola, {nickname}:\n\nTu pedido {order_no} ya está en camino.\nTransportista: {express_name}\nNúmero de seguimiento: {express_no}"],
            'refund_done' => ['[{site_name}] Reembolso realizado del pedido {order_no}', "Hola, {nickname}:\n\nTu solicitud de reembolso {refund_no} se ha completado. Hemos reembolsado {refund_amount} {currency}. Puede tardar de 1 a 7 días hábiles en aparecer en tu cuenta."],
            'refund_rejected' => ['[{site_name}] Tu solicitud de reembolso {refund_no} ha sido rechazada', "Hola, {nickname}:\n\nLo sentimos, tu solicitud de reembolso {refund_no} del pedido {order_no} ha sido rechazada.\nMotivo: {reject_reason}\n\nSi tienes alguna duda, contacta con nosotros."],
            'goods_restock' => ['[{site_name}] Un artículo que esperabas vuelve a estar disponible', "Hola, {nickname}:\n\nUn artículo del que pediste aviso vuelve a estar en stock:\n{goods_title} (opción: {spec})\n\nLas unidades son limitadas, así que inicia sesión pronto para pedirlo. Este aviso solo se envía una vez."],
            'invoice_issued' => ['[{site_name}] Tu factura del pedido {order_no} está lista', "Hola, {nickname}:\n\nSe ha emitido la factura que solicitaste para el pedido {order_no}.\nFacturado a: {title}\nNúmero de factura: {invoice_no}\nImporte: {amount}\n\nPuedes verla y descargarla en la página del pedido."],
            'invoice_rejected' => ['[{site_name}] Tu solicitud de factura del pedido {order_no} necesita cambios', "Hola, {nickname}:\n\nNo hemos podido tramitar la solicitud de factura del pedido {order_no}.\nMotivo: {reject_reason}\n\nCorrige los datos en la página del pedido y vuelve a enviarla."],
            'withdraw_paid' => ['[{site_name}] Tu retirada {withdraw_no} se ha pagado', "Hola, {nickname}:\n\nTu retirada {withdraw_no} de {amount} se ha pagado. Comprueba tu cuenta."],
        ],
        'fr' => [
            'order_paid' => ['[{site_name}] Paiement reçu pour la commande {order_no}', "Bonjour {nickname},\n\nNous avons bien reçu votre paiement de {pay_amount} {currency} pour la commande {order_no}. Nous l’expédierons dès que possible.\n\nMerci pour votre achat !"],
            'order_shipped' => ['[{site_name}] Votre commande {order_no} a été expédiée', "Bonjour {nickname},\n\nVotre commande {order_no} est en route.\nTransporteur : {express_name}\nNuméro de suivi : {express_no}"],
            'refund_done' => ['[{site_name}] Remboursement effectué pour la commande {order_no}', "Bonjour {nickname},\n\nVotre demande de remboursement {refund_no} a été traitée. Nous avons remboursé {refund_amount} {currency}. Le montant peut mettre 1 à 7 jours ouvrés à apparaître sur votre compte."],
            'refund_rejected' => ['[{site_name}] Votre demande de remboursement {refund_no} a été refusée', "Bonjour {nickname},\n\nNous sommes désolés, votre demande de remboursement {refund_no} pour la commande {order_no} a été refusée.\nMotif : {reject_reason}\n\nN’hésitez pas à nous contacter pour toute question."],
            'goods_restock' => ['[{site_name}] Un article que vous attendiez est de retour en stock', "Bonjour {nickname},\n\nUn article pour lequel vous avez demandé une alerte est de nouveau en stock :\n{goods_title} (option : {spec})\n\nLes quantités sont limitées : connectez-vous vite pour commander. Cette alerte n’est envoyée qu’une seule fois."],
            'invoice_issued' => ['[{site_name}] Votre facture pour la commande {order_no} est disponible', "Bonjour {nickname},\n\nLa facture que vous avez demandée pour la commande {order_no} a été émise.\nFacturée à : {title}\nNuméro de facture : {invoice_no}\nMontant : {amount}\n\nVous pouvez la consulter et la télécharger depuis la page de la commande."],
            'invoice_rejected' => ['[{site_name}] Votre demande de facture pour la commande {order_no} doit être modifiée', "Bonjour {nickname},\n\nNous n’avons pas pu traiter la demande de facture pour la commande {order_no}.\nMotif : {reject_reason}\n\nVeuillez corriger les informations sur la page de la commande, puis renvoyer la demande."],
            'withdraw_paid' => ['[{site_name}] Votre retrait {withdraw_no} a été payé', "Bonjour {nickname},\n\nVotre retrait {withdraw_no} de {amount} a été versé. Veuillez vérifier votre compte."],
        ],
        'de' => [
            'order_paid' => ['[{site_name}] Zahlung für Bestellung {order_no} eingegangen', "Hallo {nickname},\n\nwir haben deine Zahlung über {pay_amount} {currency} für die Bestellung {order_no} erhalten. Wir versenden sie so schnell wie möglich.\n\nVielen Dank für deinen Einkauf!"],
            'order_shipped' => ['[{site_name}] Deine Bestellung {order_no} wurde versendet', "Hallo {nickname},\n\ndeine Bestellung {order_no} ist unterwegs.\nVersanddienst: {express_name}\nSendungsnummer: {express_no}"],
            'refund_done' => ['[{site_name}] Erstattung für Bestellung {order_no} veranlasst', "Hallo {nickname},\n\ndein Erstattungsantrag {refund_no} wurde abgeschlossen. Wir haben {refund_amount} {currency} erstattet. Es kann 1 bis 7 Werktage dauern, bis der Betrag auf deinem Konto erscheint."],
            'refund_rejected' => ['[{site_name}] Dein Erstattungsantrag {refund_no} wurde abgelehnt', "Hallo {nickname},\n\nes tut uns leid, dein Erstattungsantrag {refund_no} für die Bestellung {order_no} wurde abgelehnt.\nGrund: {reject_reason}\n\nBei Fragen kannst du dich gern an uns wenden."],
            'goods_restock' => ['[{site_name}] Ein Artikel, auf den du gewartet hast, ist wieder da', "Hallo {nickname},\n\nein Artikel, für den du eine Benachrichtigung eingerichtet hast, ist wieder auf Lager:\n{goods_title} (Variante: {spec})\n\nDer Vorrat ist begrenzt, melde dich also bald an, um zu bestellen. Diese Benachrichtigung wird nur einmal gesendet."],
            'invoice_issued' => ['[{site_name}] Deine Rechnung für Bestellung {order_no} ist fertig', "Hallo {nickname},\n\ndie Rechnung, die du für die Bestellung {order_no} angefordert hast, wurde ausgestellt.\nRechnungsempfänger: {title}\nRechnungsnummer: {invoice_no}\nBetrag: {amount}\n\nDu kannst sie auf der Bestellseite ansehen und herunterladen."],
            'invoice_rejected' => ['[{site_name}] Deine Rechnungsanfrage für Bestellung {order_no} muss angepasst werden', "Hallo {nickname},\n\nwir konnten die Rechnungsanfrage für die Bestellung {order_no} nicht bearbeiten.\nGrund: {reject_reason}\n\nBitte korrigiere die Angaben auf der Bestellseite und sende die Anfrage erneut."],
            'withdraw_paid' => ['[{site_name}] Deine Auszahlung {withdraw_no} wurde überwiesen', "Hallo {nickname},\n\ndeine Auszahlung {withdraw_no} über {amount} wurde überwiesen. Bitte prüfe dein Konto."],
        ],
        'ja' => [
            'order_paid' => ['【{site_name}】ご注文 {order_no} のお支払いを確認しました', "{nickname} 様\n\nご注文 {order_no} のお支払い {pay_amount} {currency} を確認しました。できるだけ早く発送いたします。\n\nご購入ありがとうございます。"],
            'order_shipped' => ['【{site_name}】ご注文 {order_no} を発送しました', "{nickname} 様\n\nご注文 {order_no} を発送しました。\n配送会社：{express_name}\n追跡番号：{express_no}"],
            'refund_done' => ['【{site_name}】ご注文 {order_no} の返金が完了しました', "{nickname} 様\n\n返金申請 {refund_no} の処理が完了し、{refund_amount} {currency} を返金しました。口座への反映には通常 1〜7 営業日かかります。"],
            'refund_rejected' => ['【{site_name}】返金申請 {refund_no} は承認されませんでした', "{nickname} 様\n\n申し訳ございません。ご注文 {order_no} の返金申請 {refund_no} は承認されませんでした。\n理由：{reject_reason}\n\nご不明な点がございましたら、お問い合わせください。"],
            'goods_restock' => ['【{site_name}】お待ちの商品が再入荷しました', "{nickname} 様\n\n再入荷のお知らせをご登録いただいた商品が入荷しました。\n{goods_title}（タイプ：{spec}）\n\n数に限りがありますので、お早めにログインしてご注文ください。このお知らせは 1 回のみお送りしています。"],
            'invoice_issued' => ['【{site_name}】ご注文 {order_no} の請求書を発行しました', "{nickname} 様\n\nご注文 {order_no} についてご申請いただいた請求書を発行しました。\n宛名：{title}\n請求書番号：{invoice_no}\n金額：{amount}\n\n注文詳細ページでご確認・ダウンロードいただけます。"],
            'invoice_rejected' => ['【{site_name}】ご注文 {order_no} の請求書申請は承認されませんでした', "{nickname} 様\n\nご注文 {order_no} の請求書申請を処理できませんでした。\n理由：{reject_reason}\n\n注文詳細ページで内容を修正のうえ、もう一度ご申請ください。"],
            'withdraw_paid' => ['【{site_name}】出金 {withdraw_no} のお支払いが完了しました', "{nickname} 様\n\n出金申請 {withdraw_no}（{amount}）のお支払いが完了しました。口座をご確認ください。"],
        ],
        'ru' => [
            'order_paid' => ['[{site_name}] Оплата заказа {order_no} получена', "Здравствуйте, {nickname}!\n\nМы получили вашу оплату {pay_amount} {currency} за заказ {order_no}. Отправим его как можно скорее.\n\nСпасибо за покупку!"],
            'order_shipped' => ['[{site_name}] Ваш заказ {order_no} отправлен', "Здравствуйте, {nickname}!\n\nВаш заказ {order_no} уже в пути.\nСлужба доставки: {express_name}\nТрек-номер: {express_no}"],
            'refund_done' => ['[{site_name}] Возврат по заказу {order_no} выполнен', "Здравствуйте, {nickname}!\n\nВаша заявка на возврат {refund_no} обработана. Мы вернули {refund_amount} {currency}. Деньги могут поступить на счёт в течение 1–7 рабочих дней."],
            'refund_rejected' => ['[{site_name}] Ваша заявка на возврат {refund_no} отклонена', "Здравствуйте, {nickname}!\n\nК сожалению, ваша заявка на возврат {refund_no} по заказу {order_no} отклонена.\nПричина: {reject_reason}\n\nЕсли у вас есть вопросы, свяжитесь с нами."],
            'goods_restock' => ['[{site_name}] Товар, которого вы ждали, снова в наличии', "Здравствуйте, {nickname}!\n\nТовар, о поступлении которого вы просили сообщить, снова в наличии:\n{goods_title} (вариант: {spec})\n\nКоличество ограничено, поэтому войдите и оформите заказ поскорее. Это уведомление отправляется только один раз."],
            'invoice_issued' => ['[{site_name}] Счёт по заказу {order_no} готов', "Здравствуйте, {nickname}!\n\nСчёт, который вы запросили по заказу {order_no}, выставлен.\nПолучатель счёта: {title}\nНомер счёта: {invoice_no}\nСумма: {amount}\n\nПосмотреть и скачать его можно на странице заказа."],
            'invoice_rejected' => ['[{site_name}] Запрос счёта по заказу {order_no} нужно исправить', "Здравствуйте, {nickname}!\n\nМы не смогли обработать запрос счёта по заказу {order_no}.\nПричина: {reject_reason}\n\nИсправьте данные на странице заказа и отправьте запрос снова."],
            'withdraw_paid' => ['[{site_name}] Вывод {withdraw_no} выплачен', "Здравствуйте, {nickname}!\n\nВаша заявка на вывод {withdraw_no} на сумму {amount} выплачена. Проверьте свой счёт."],
        ],
    ];

    /** 协议的默认标题：语言 => 协议 => 标题 */
    public const PROTOCOLS = [
        'es' => ['user_agreement' => 'Condiciones del servicio', 'privacy_policy' => 'Política de privacidad', 'refund_policy' => 'Política de devoluciones y reembolsos', 'shipping_policy' => 'Política de envíos', 'distribution_agreement' => 'Acuerdo de afiliados'],
        'fr' => ['user_agreement' => 'Conditions d’utilisation', 'privacy_policy' => 'Politique de confidentialité', 'refund_policy' => 'Politique de retour et de remboursement', 'shipping_policy' => 'Politique de livraison', 'distribution_agreement' => 'Contrat d’affiliation'],
        'de' => ['user_agreement' => 'Nutzungsbedingungen', 'privacy_policy' => 'Datenschutzerklärung', 'refund_policy' => 'Rückgabe- und Erstattungsrichtlinie', 'shipping_policy' => 'Versandrichtlinie', 'distribution_agreement' => 'Partnervereinbarung'],
        'ja' => ['user_agreement' => '利用規約', 'privacy_policy' => 'プライバシーポリシー', 'refund_policy' => '返品・返金ポリシー', 'shipping_policy' => '配送ポリシー', 'distribution_agreement' => 'アフィリエイト規約'],
        'ru' => ['user_agreement' => 'Пользовательское соглашение', 'privacy_policy' => 'Политика конфиденциальности', 'refund_policy' => 'Политика возврата', 'shipping_policy' => 'Политика доставки', 'distribution_agreement' => 'Партнёрское соглашение'],
    ];

    /**
     * 邮箱验证码邮件：语言 => [标题, 注册时的第一行, 找回密码时的第一行, 有效期说明, 不是本人操作的说明]。
     * 标题里 {site} 是站点名称、{code} 是验证码；有效期说明里 {minutes} 是分钟数。
     */
    public const VERIFY_MAIL = [
        'es' => ['[{site}] Tu código de verificación es {code}', 'Usa este código para crear tu cuenta:', 'Usa este código para restablecer tu contraseña:', 'El código caduca en {minutes} minutos. No lo compartas con nadie.', 'Si no lo has solicitado tú, puedes ignorar este correo.'],
        'fr' => ['[{site}] Votre code de vérification est {code}', 'Utilisez ce code pour créer votre compte :', 'Utilisez ce code pour réinitialiser votre mot de passe :', 'Ce code expire dans {minutes} minutes. Ne le communiquez à personne.', 'Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.'],
        'de' => ['[{site}] Dein Bestätigungscode lautet {code}', 'Mit diesem Code erstellst du dein Konto:', 'Mit diesem Code setzt du dein Passwort zurück:', 'Der Code ist {minutes} Minuten gültig. Gib ihn an niemanden weiter.', 'Wenn du das nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.'],
        'ja' => ['【{site}】認証コード {code}', 'アカウント登録の認証コードです：', 'パスワード再設定の認証コードです：', '認証コードの有効期限は {minutes} 分です。他の人には教えないでください。', 'お心当たりがない場合は、このメールを無視してください。'],
        'ru' => ['[{site}] Ваш код подтверждения: {code}', 'Используйте этот код, чтобы создать аккаунт:', 'Используйте этот код, чтобы сбросить пароль:', 'Код действует {minutes} мин. Никому его не сообщайте.', 'Если вы этого не запрашивали, просто проигнорируйте это письмо.'],
    ];

    /** 这种语言在这里有没有文字；有就返回它在上面几个表里的键（es-MX → es），没有返回 null */
    public static function base(string $lang): ?string
    {
        $base = strtolower(explode('-', $lang)[0]);

        return in_array($base, self::LANGS, true) ? $base : null;
    }
}
