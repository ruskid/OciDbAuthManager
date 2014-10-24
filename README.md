yii1 OciDbAuthManager
================

The problem is that Oracle use capital letters because of naming conventions. Provided schema-oci.sql and DbAuthManager simply don't work. 

OciDbAuthManager is the rewrite of DbAuthManager with column names capitalized. SQLcheck was removed also, because not needed.

1. Run the quieries from schema-oci.sql to create authorization tables.
2. upload OciDbAuthManager.php to components folder
3. call it in the main.php
```php
 'authManager'=>array(
    'class'=>'OciCDbAuthManager',
  ),
```
