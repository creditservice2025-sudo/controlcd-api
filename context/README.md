# 📚 Carpeta de Documentación - /context

Esta carpeta contiene **documentación técnica y guías** que NO deben ser desplegadas al servidor de producción.

## 📁 Contenido

- `GUIA_PRODUCCION_PASO_A_PASO.md` - Guía completa para desplegar en producción
- `DEPLOYMENT.md` - Documentación general de despliegue
- `FIX_CORS.md` - Solución de problemas de CORS
- `SETUP_COMPLETO.md` - Documentación de configuración completa
- Otros documentos de referencia y troubleshooting

## ⚙️ Configuración

Esta carpeta está **excluida automáticamente** del despliegue mediante:

- ✅ Script `deploy-to-server.sh` (línea con `--exclude='context/'`)
- ✅ Mantiene el repositorio Git limpio y organizado
- ✅ Solo se usa localmente para consulta y desarrollo

## 📌 Propósito

Esta carpeta existe para:

1. **Mantener documentación centralizada** sin contaminar el código en producción
2. **Facilitar consultas** durante desarrollo y mantenimiento
3. **Preservar conocimiento** sobre configuraciones y procedimientos
4. **Servir de referencia** para futuros despliegues

## 🚫 NO Subir al Servidor

**Importante:** Esta carpeta y su contenido **NO** deben estar en el servidor de producción. El script de deploy está configurado para excluirla automáticamente.

---

**Última actualización:** Noviembre 2025
