# Тестовые пользователи

[← README](../../README.md)

20 тестовых пользователей на локальном сервере (`http://localhost:8080`, БД из `config/db.php`), созданы 2026-10-07 через `/auth/signup`. Каждому загружен аватар (`/files/uploadImage` → `/profile/setAvatar`); фото взяты со [randomuser.me](https://randomuser.me) (портреты 128×128, пол совпадает с именем).

Тестовый раннер (`php tests/run.php`) работает с отдельной БД `serverai_test` и пересоздаёт её — этих пользователей там нет.

| userId | Логин              | Пароль        | Имя              | fileId аватара |
| -----: | ------------------ | ------------- | ---------------- | -------------: |
|     19 | `anna_smirnova`    | `xhvjMIO8511` | Анна Смирнова    |             11 |
|     20 | `dmitry_ivanov`    | `jppzGBR9474` | Дмитрий Иванов   |             12 |
|     21 | `elena_kuznetsova` | `hzvsDEN5533` | Елена Кузнецова  |             13 |
|     22 | `alexey_popov`     | `uroaIIQ5286` | Алексей Попов    |             14 |
|     23 | `olga_sokolova`    | `dlmyBTR6810` | Ольга Соколова   |             15 |
|     24 | `sergey_lebedev`   | `cclrBYN7758` | Сергей Лебедев   |             16 |
|     25 | `maria_kozlova`    | `wohzAFM4971` | Мария Козлова    |             17 |
|     26 | `andrey_novikov`   | `wmpqEVA7007` | Андрей Новиков   |             18 |
|     27 | `natalia_morozova` | `bhirQDU8772` | Наталья Морозова |             19 |
|     28 | `pavel_volkov`     | `mkgzQQQ2383` | Павел Волков     |             20 |
|     29 | `irina_fedorova`   | `esmcJYF7286` | Ирина Фёдорова   |             21 |
|     30 | `maxim_orlov`      | `mqizEUW3103` | Максим Орлов     |             22 |
|     31 | `ekaterina_belova` | `hnttHRX1272` | Екатерина Белова |             23 |
|     32 | `nikolay_zaytsev`  | `wvoeOOB4878` | Николай Зайцев   |             24 |
|     33 | `svetlana_pavlova` | `yyogJKP4319` | Светлана Павлова |             25 |
|     34 | `ivan_semenov`     | `cxymLZC7062` | Иван Семёнов     |             26 |
|     35 | `yulia_golubeva`   | `tdvmHLM8128` | Юлия Голубева    |             27 |
|     36 | `artem_vinogradov` | `bwamNVF8240` | Артём Виноградов |             28 |
|     37 | `daria_bogdanova`  | `ngxxHAO1771` | Дарья Богданова  |             29 |
|     38 | `kirill_romanov`   | `flloMHO8085` | Кирилл Романов   |             30 |

## Пример входа

```bash
curl -c cookies.txt --data "login=anna_smirnova&password=xhvjMIO8511" http://localhost:8080/auth/login
```
