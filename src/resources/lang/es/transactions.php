<?php

return [
    'entitlement-created' => ":user_email creó :sku_title para :object",
    'entitlement-billed' => ":sku_title para :object se factura a :amount",
    'entitlement-deleted' => ":user_email eliminó :sku_title para :object",
    'entitlement-created-short' => "Añadido :sku_title para :object",
    'entitlement-billed-short' => "Facturado :sku_title para :object",
    'entitlement-deleted-short' => "Eliminado :sku_title para :object",
    'wallet-award' => "Bono de :amount otorgado a :wallet; :description",
    'wallet-chback' => ":amount fue revertido desde :wallet",
    'wallet-credit' => ":amount fue añadido al saldo de :wallet",
    'wallet-debit' => ":amount fue deducido del saldo de :wallet",
    'wallet-penalty' => "El saldo de :wallet se redujo en :amount; :description",
    'wallet-refund' => ":amount fue reembolsado desde :wallet",
    'wallet-award-short' => "Bono: :description",
    'wallet-chback-short' => "Contracargo",
    'wallet-credit-short' => "Pago",
    'wallet-debit-short' => "Deducción",
    'wallet-penalty-short' => "Cargo: :description",
    'wallet-refund-short' => "Reembolso: :description",
];
