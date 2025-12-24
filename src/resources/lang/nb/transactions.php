<?php

return [
    'entitlement-created' => ":user_email opprettet :sku_title for :object",
    'entitlement-billed' => ":sku_title for :object faktureres med :amount",
    'entitlement-deleted' => ":user_email slettet :sku_title for :object",
    'entitlement-created-short' => "La til :sku_title for :object",
    'entitlement-billed-short' => "Fakturert :sku_title for :object",
    'entitlement-deleted-short' => "Slettet :sku_title for :object",
    'wallet-award' => "Bonus på :amount tildelt :wallet; :description",
    'wallet-chback' => ":amount ble tilbakeført fra :wallet",
    'wallet-credit' => ":amount ble lagt til saldoen på :wallet",
    'wallet-debit' => ":amount ble trukket fra saldoen på :wallet",
    'wallet-penalty' => "Saldoen på :wallet ble redusert med :amount; :description",
    'wallet-refund' => ":amount ble refundert fra :wallet",
    'wallet-award-short' => "Bonus: :description",
    'wallet-chback-short' => "Tilbakeføring",
    'wallet-credit-short' => "Betaling",
    'wallet-debit-short' => "Fradrag",
    'wallet-penalty-short' => "Belastning: :description",
    'wallet-refund-short' => "Refusjon: :description",
];
