# Changelog

## [1.0.1] - 2025-10-24

### Security Fixes
- **SQL Injection Protection**: Added proper input validation and type casting for all SQL queries
  - Fixed SQL injection vulnerabilities in `ajax_cz_imageslider.php`
  - Fixed SQL injection vulnerabilities in `Cz_HomeSlide.php`
  - Fixed SQL injection vulnerabilities in `cz_imageslider.php`
  - Added validation checks for all user inputs used in database queries

- **XSS Protection**: Added output sanitization
  - Added `htmlspecialchars()` for title, legend, and URL outputs
  - Added `pSQL()` for image paths
  - Added `basename()` to prevent directory traversal attacks

### Performance Improvements
- **Database Optimization**: Added indexes for better query performance
  - Added index on `id_shop` in `czhomeslider` table
  - Added composite index on `active` and `position` in `czhomeslider_slides` table
  - Added index on `id_lang` in `czhomeslider_slides_lang` table
  - These improvements significantly enhance performance for sites with 5000-10000 products

- **Caching Improvements**:
  - Improved cache key generation for better cache isolation
  - Enhanced PrestaShop cache compatibility
  - Added proper cache invalidation

### Other Changes
- Updated module version to 1.0.1
- Added upgrade script for existing installations
- Minimal code changes to maintain backward compatibility

## [1.0.0] - Initial Release
