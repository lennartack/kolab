<?php

return [
    'entitlement-created' => ":user_email 為 :object 建立了 :sku_title",
    'entitlement-billed' => ":object 的 :sku_title 以 :amount 計費",
    'entitlement-deleted' => ":user_email 刪除了 :object 的 :sku_title",
    'entitlement-created-short' => "已新增 :object 的 :sku_title",
    'entitlement-billed-short' => "已計費 :object 的 :sku_title",
    'entitlement-deleted-short' => "已刪除 :object 的 :sku_title",
    'wallet-award' => "已向 :wallet 發放 :amount 獎勵；:description",
    'wallet-chback' => ":amount 已從 :wallet 退回",
    'wallet-credit' => ":amount 已加入 :wallet 的餘額",
    'wallet-debit' => ":amount 已從 :wallet 的餘額中扣除",
    'wallet-penalty' => ":wallet 的餘額減少了 :amount；:description",
    'wallet-refund' => ":amount 已從 :wallet 退款",
    'wallet-award-short' => "獎勵：:description",
    'wallet-chback-short' => "退費沖正",
    'wallet-credit-short' => "付款",
    'wallet-debit-short' => "扣除",
    'wallet-penalty-short' => "扣款：:description",
    'wallet-refund-short' => "退款：:description",
];
