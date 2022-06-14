#!/bin/bash

docker-compose exec gc-app /bin/sh -c 'cd /var/www/yii && php .phpAnalysis/run.php'
