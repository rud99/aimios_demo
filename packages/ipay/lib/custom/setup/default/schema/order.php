<?php

$tableName = 'mshop_order';

return [
    'table' => [
        $tableName => function (\Doctrine\DBAL\Schema\Schema $schema) use ($tableName) {

            $table = $schema->getTable($tableName);
            $table->addColumn('transaction_id', 'string', ['length' => 256, 'notnull' => false]);
            $table->addIndex( ['transaction_id'],'idx_transaction_id' );
            return $schema;
        },
    ],
];