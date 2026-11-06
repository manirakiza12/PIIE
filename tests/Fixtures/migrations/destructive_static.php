<?php
class StaticFixture {
    public function up() { Payroll::destroy([1, 2]); }
    public function down() {}
}
