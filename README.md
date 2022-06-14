## Install

Создаем в корне папку ```.phpAnalysis```

Папку можно назвать как угодно, как пример используется ```.phpAnalysis```

Копируем содержимое в эту папку

Это необходимо что бы не прокидывать в докер доп. папку

Убираем папку из отслеживания гита добавить в файл ```.git/info/excluded``` строчку:

    /.phpAnalysis/

Заходим в контейнер gc-app и устанавливаем:

### git

    sudo apt update
    sudo apt install git

### composer

https://getcomposer.org/download/

### phpcs

    composer global require "squizlabs/php_codesniffer=*"

### phpstan

    composer global require phpstan/phpstan

---

## Usage
### Запуск из корня проекта

    .phpAnalysis/runDockerCommand.sh

### Запуск из phpstorm

Нужно создать новую конфигурацию запуска в "Run/Debug Configurations"

**Тип:** ```Shell Script```

**Script Path:** путь до ```runDockerCommand.sh```

**Working directory**: корень проекта

---

## Additional

Скрипты для git difftool взяты отсюда:
https://gist.github.com/mdawaffe/529e6b3ee820e777c2cfd2f8255d187f
