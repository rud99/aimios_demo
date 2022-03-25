<?php

$tableName = 'mshop_order_base';

return [
    'table' => [
        $tableName => function (\Doctrine\DBAL\Schema\Schema $schema) use ($tableName) {

            $table = $schema->getTable($tableName);
            $table->addColumn('transaction_id', 'string', ['length' => 256, 'notnull' => false]);

            return $schema;
        },
    ],
];