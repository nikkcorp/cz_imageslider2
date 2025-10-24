# Changelog

## [1.0.1] - 2025-10-24

### Security Fixes
- **SQL Injection Protection**: Added proper input validation and type casting for all SQL queries
  - Fixed SQL injection vulnerabilities in `ajax_cz_imageslider.php`
  - Fixed SQL injection vulnerabilities in `Cz_HomeSlide.php`
  - Fixed SQL injection vulnerabilities in `cz_imageslider.php`
  - Added validation checks for all user inputs used in database queries
  - All parameters properly cast to (int) before SQL queries

- **Path Traversal Protection**:
  - Added `basename()` in getWidgetVariables to prevent directory traversal attacks
  - Image paths are sanitized before filesystem operations

- **XSS Protection**:
  - Data already protected through ObjectModel validation (isCleanHtml, isUrl)
  - Template-level escaping handled by Smarty engine

### Performance Improvements
- **Database Optimization**: Added indexes for better query performance
  - Added index on `id_shop` in `czhomeslider` table
  - Added composite index on `active` and `position` in `czhomeslider_slides` table
  - Added index on `id_lang` in `czhomeslider_slides_lang` table
  - These improvements significantly enhance performance for sites with 5000-10000 products

- **Caching**:
  - Uses standard PrestaShop getCacheId() for proper cache isolation by shop/language/currency
  - Module cache cleared on slide changes without affecting global PrestaShop cache
  - Full compatibility with PrestaShop 1.7.8.11 caching system

### Other Changes
- Updated module version to 1.0.1
- Added upgrade script for existing installations
- Minimal code changes to maintain backward compatibility
- No performance degradation - only improvements

## [1.0.0] - Initial Release
