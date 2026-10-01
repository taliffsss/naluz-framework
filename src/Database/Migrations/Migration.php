<?php

declare(strict_types=1);

namespace Naluz\Database\Migrations;

use Naluz\Database\Schema\Schema;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    abstract public function down(Schema $schema): void;
}
