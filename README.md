yii1 OciDbAuthManager
================

The problem is that Oracle use capital letters because of naming conventions. Provided schema-oci.sql and DbAuthManager simply don't work. 

OciDbAuthManager is the rewrite of DbAuthManager with column names capitalized. SQLcheck was removed also, because it is not needed.

1. Run the quieries from schema-oci.sql to create authorization tables.
2. Upload OciDbAuthManager.php to components folder
3. And call it in the main.php
```php
 'authManager'=>array(
    'class'=>'OciCDbAuthManager',
  ),
```
