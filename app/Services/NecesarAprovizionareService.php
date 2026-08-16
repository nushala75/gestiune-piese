<?php

namespace App\Services;

use App\Models\Gestiune;
use App\Models\Produs;

class NecesarAprovizionareService
{
    public function sincronizeaza(Produs $produs, ?Gestiune $gestiune = null): void
    {
        // Cantitatea de comandat este administrata exclusiv manual.
        // Sincronizarile de stoc/receptie/import nu trebuie sa o modifice automat.
    }
}
