<?php

return [
    'entitlement-created' => ":user_email 为 :object 创建了 :sku_title",
    'entitlement-billed' => ":object 的 :sku_title 按 :amount 计费",
    'entitlement-deleted' => ":user_email 删除了 :object 的 :sku_title",
    'entitlement-created-short' => "已添加 :object 的 :sku_title",
    'entitlement-billed-short' => "已计费 :object 的 :sku_title",
    'entitlement-deleted-short' => "已删除 :object 的 :sku_title",
    'wallet-award' => "向 :wallet 发放 :amount 奖励；:description",
    'wallet-chback' => ":amount 已从 :wallet 退回",
    'wallet-credit' => ":amount 已添加到 :wallet 的余额中",
    'wallet-debit' => ":amount 已从 :wallet 的余额中扣除",
    'wallet-penalty' => ":wallet 的余额减少了 :amount；:description",
    'wallet-refund' => ":amount 已从 :wallet 退款",
    'wallet-award-short' => "奖励：:description",
    'wallet-chback-short' => "退款冲正",
    'wallet-credit-short' => "支付",
    'wallet-debit-short' => "扣除",
    'wallet-penalty-short' => "扣费：:description",
    'wallet-refund-short' => "退款：:description",
];
