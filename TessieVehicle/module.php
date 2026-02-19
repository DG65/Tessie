<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }
}
