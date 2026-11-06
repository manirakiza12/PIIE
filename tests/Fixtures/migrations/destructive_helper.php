<?php
class HelperFixture {
    public function up() { $this->cleanupRows(); }
    private function cleanupRows() { DB::table('payroll')->where('status', 'draft')->delete(); }
    public function down() {}
}
