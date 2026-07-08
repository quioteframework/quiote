<?php
class OutputBufferNormalizer
{
    private readonly int $start;
    public function __construct()
    {
        $this->start = ob_get_level();
    }
    public function normalize(): void
    {
        $current = ob_get_level();
        if ($current > $this->start) {
            // Close extras
            while (ob_get_level() > $this->start) {
                ob_end_clean();
            }
        } elseif ($current < $this->start) {
            // Re-open a dummy buffer to restore expected baseline
            while (ob_get_level() < $this->start) {
                ob_start();
            }
        }
    }
    public function startLevel(): int { return $this->start; }
}
?>