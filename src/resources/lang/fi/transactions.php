<?php

return [
    'entitlement-created' => ":user_email loi :sku_title kohteelle :object",
    'entitlement-billed' => ":sku_title kohteelle :object veloitetaan summalla :amount",
    'entitlement-deleted' => ":user_email poisti :sku_title kohteelta :object",
    'entitlement-created-short' => "Lisätty :sku_title kohteelle :object",
    'entitlement-billed-short' => "Veloitettu :sku_title kohteelle :object",
    'entitlement-deleted-short' => "Poistettu :sku_title kohteelta :object",
    'wallet-award' => ":amount bonusta myönnetty lompakolle :wallet; :description",
    'wallet-chback' => ":amount hyvitettiin lompakosta :wallet",
    'wallet-credit' => ":amount lisättiin lompakon :wallet saldoon",
    'wallet-debit' => ":amount vähennettiin lompakon :wallet saldosta",
    'wallet-penalty' => "Lompakon :wallet saldoa vähennettiin :amount; :description",
    'wallet-refund' => ":amount palautettiin lompakosta :wallet",
    'wallet-award-short' => "Bonus: :description",
    'wallet-chback-short' => "Takaisinperintä",
    'wallet-credit-short' => "Maksu",
    'wallet-debit-short' => "Vähennys",
    'wallet-penalty-short' => "Veloitus: :description",
    'wallet-refund-short' => "Hyvitys: :description",
];
