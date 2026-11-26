<?php
// Public : le devis en ligne du couple (lecture de l'offre, confirmation, réactions).
require __DIR__ . '/stockage_lib.php';
st_servir(['olaflora-live-'], ['olaflora-live-confirm-', 'olaflora-live-react-', 'olaflora-live-viewer-'], 'public');
