---
description: Cómo ejecutar la reconciliación de pagos desvinculados
---

Este workflow permite corregir las deudas inexistentes (moras) causadas por pagos que no se vincularon correctamente a las cuotas.

1. **Auditar el ambiente** (Identificar casos afectados):
```bash
php reconcile_payments.php
```

2. **Reparar un crédito específico** (Ejemplo ID 816):
```bash
php reconcile_payments.php --fix --id=816
```

3. **Reparar todos los créditos detectados** (Ejecución masiva):
```bash
php reconcile_payments.php --fix
```

4. **Verificar limpieza**:
Vuelva a ejecutar el paso 1. Debería mostrar "Casos detectados: 0".
