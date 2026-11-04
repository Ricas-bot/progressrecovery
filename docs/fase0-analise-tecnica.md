# Fase 0 — Análise técnica

**Projecto:** Totara Progress Recovery (Extractor + `local_progressrecovery`)
**Data:** 30/10/2026
**Estado:** rascunho para validação — contém pendentes (secção 12)

---

## 1. Fontes desta análise

| Fonte | O que foi verificado |
|---|---|
| Código Totara 19.1.2 do ambiente de teste (`C:\wamp64\www\totara`) | `install.xml` de todos os componentes envolvidos e APIs de escrita |
| Consultas de leitura na origem (cPanel, BD `zurich_totara`) | Versões, configuração, plugins, tabelas `customcert`, contagem de emissões |
| Consultas de leitura no destino (AlmaLinux, BD `totaranew`) | As mesmas consultas |

Os módulos core do ambiente de teste têm a mesma versão da origem e do destino (2025070800). Por isso, os esquemas abaixo são os esquemas reais da produção. A excepção é o `mod_customcert`, que não está instalado no ambiente de teste (ver 5.8).

---

## 2. Ambientes confirmados

| Item | Origem (cPanel) | Destino (AlmaLinux) | Teste local |
|---|---|---|---|
| Totara | 19.1.2 (build 20250825.00) | 19.1.2 (build 20250825.00) | 19.1.2 (build 20250825.00) |
| Core (`version`) | 2025070800 | 2025070800 | 2025070800 |
| `siteidentifier` | `1F2HReq3…academia.zurich.com` | igual | diferente |
| Prefixo | `mdl_` | `mdl_` | `ttr_` |
| `timezone` | Europe/Lisbon | Europe/Lisbon | — |
| `forcetimezone` | 99 (fuso de cada utilizador) | 99 | — |
| `mod_feedback`, `mod_quiz`, `mod_assign`, `mod_scorm` | 2025070800 | 2025070800 | 2025070800 |
| `mod_customcert` | 2016052321 | 2016052321 | 2016052321 (instalado a 30/10) |
| `block_dedication` | usado pelo `view.php` do customcert | idem | 2020041000 (instalado a 30/10) |
| `tool_log` `enabled_stores` | — | `logstore_standard` | — |
| `customcert` com `requiredtime > 0` | — | 223 de 227 | — |
| PHP | 8.2.35, `memory_limit` 512M, `max_execution_time` 60 s, `ZipArchive` disponível | — | 128M / 120 s (WampServer 3.4.2) |
| Base de dados | MariaDB 10.11.19 | por confirmar | — |
| `customcertelement_*` (15 subplugins) | iguais ao destino | iguais à origem | — |
| `mod_certificate` (legado Totara) | 2025070800 | 2025070800 | 2025070800 |
| `qtype_*` (18 tipos) | 2025070800 | 2025070800 | 2025070800 |

**Conclusões:**
- O `siteidentifier` é igual na origem e no destino, o que confirma que a origem é uma cópia do destino. Esta igualdade fica como verificação obrigatória no plugin.
- O código nunca deve fixar o prefixo: o teste usa `ttr_` e a produção usa `mdl_`. No plugin isto é automático (`{tabela}`); no Extractor, o prefixo é configurável e validado no diagnóstico.

---

## 3. Período e fuso horário

- A hora de Verão terminou a **25/10/2026**, por isso Lisboa estava em WET (UTC+0) durante todo o período.
- Intervalo em epoch (semiaberto): **`[1792972800, 1793232000)`**, ou seja, de 26/10/2026 00:00:00 a 28/10/2026 23:59:59 (Europe/Lisbon).
- O cálculo será sempre feito em PHP com `DateTimeZone` a partir do `timezone` lido da tabela `config`, e nunca com `UNIX_TIMESTAMP()` do MySQL, que depende do fuso da sessão do servidor.
- Com `forcetimezone = 99`, cada utilizador vê as datas no seu próprio fuso. Os timestamps guardados são UTC e não são afectados.

**Contagem de `customcert_issues` no período:** origem = 113 (confirmado com epochs pelo diagnóstico de 02/11), destino = **4**.

**Cronologia reconstruída a partir das emissões (hora de Lisboa):**

| Evento | Evidência |
|---|---|
| **Cópia origem ← destino** | **fim de 23/10** (eventos por dia e gamas de `id` iguais nas duas BD de 19 a 23/10; último `id` comum: `8741994`, confirmado a 02/11) |
| Primeira actividade só no destino | 24/10: 46 eventos de 2 utilizadores (`id` 8741995 a 8742040) |
| **sv1 parado** | 24/10 12:42:16, no último evento `id` 8742040. O sv2 partiu deste estado: tem os mesmos eventos de 24/10 e continua a partir do `id` 8742041 |
| Primeira actividade só na origem | 25/10: 71 eventos |
| Primeiro utilizador criado na origem | 26/10 09:17:48 (`p1062254`, `id` 6594). Último real: 28/10 17:08:11 (`p1045930`) |
| Primeiro utilizador criado no destino | 28/10 20:07:29 (`pedro2.edu`, `id` 6594) |
| Primeira emissão na origem | 26/10 19:14:48 (`id` 51878 na origem) |
| Primeira emissão no destino após a cópia | **28/10 21:53:43** (`id` 51878 no destino) |
| Emissões no destino até ao fim do período | 21:53, 21:59, 22:27, 23:55 de 28/10 |
| Cron da origem | **ainda activo** — `email_certificate_task` correu a 30/10 17:30 |

**Conclusões:**
- O destino voltou a receber utilizadores na **noite de 28/10**, antes do fim do período. Há uma **janela de sobreposição** (28/10 ~21:50 → 23:59) com escrita nas duas instalações.
- O período real a recuperar é **[instante da cópia, instante em que a origem deixou de ser usada]**, e não exactamente 26/10 00:00–28/10 23:59. **Recomendação: extrair desde 24/10 00:00 (`1792796400`)**, para incluir os 71 eventos de 25/10 que só existem na origem e qualquer progresso associado.
- O diagnóstico do Extractor tem de reportar actividade na origem **antes de 26/10 e depois de 28/10** (por dia, a partir de `logstore_standard_log` e dos `timemodified`), para confirmar os limites.
- O cliente confirmou a 02/11 que a origem não está a ser usada. O diagnóstico é coerente com isso: **nenhuma tabela de progresso tem alterações depois de 28/10**, e os 232 eventos posteriores são de sistema ou de administração (204 `email_failed`, relatórios, actualização de idioma). Não é preciso congelar a origem (P13).

**Ambiente de testes sv1 (02/11):** servidor inicial, com o mesmo `siteidentifier`, as mesmas versões e sem nenhum dado posterior a 24/10 12:42. Último utilizador `6593`, último certificado `51877`. Bloqueio de emails activo (`noemailever`).

- **Serve** para as Fases 2 a 5: instalação, validação do pacote, comparação e escrita.
- **Não reproduz o sv2 actual:** não tem os utilizadores `6594` a `6608` do sv2, as 4 emissões de 28/10 nem o uso desde 29/10. Por isso, a colisão de `id` de utilizadores, a regra "fica o mais recente do destino" e o conflito de `p1044867` têm de ser simulados no sv1.
- **A Fase 6 exige uma cópia recente do sv2**, restaurada no sv1 (P21).

### 3.1 Resultados do diagnóstico na origem (02/11/2026)

> **Pacote real (02/11):** 6,8 MB, 48,9 MB descomprimido, 48 entradas, SHA-256 `35f6f2b3…`. Checksums, manifesto e linhas verificados com o validador do plugin; a assinatura fica para a verificação no plugin. 412 utilizadores, 62 cursos, 245 actividades. Sem referências em falta nem UTF-8 inválido. SCORM: 34 614 registos alterados e 10 581 dependências, em 643 pares (utilizador, SCORM) e 682 tentativas. Feedback: 141 respostas anónimas em 47 inquéritos e 49 identificadas em 2 inquéritos.

Todas as 51 tabelas verificadas têm exactamente as colunas esperadas, sem colunas adicionais. Nenhum componente é incompatível.

| Componente | Tabela | No período | Utilizadores |
|---|---|---|---|
| Conclusão de actividades | `course_modules_completion` | 645 | 153 |
| Conclusão de cursos | `course_completions` | 380 | 181 |
| | `course_completion_crit_compl` | 485 | 150 |
| | `course_completion_log` (evidência) | 2 477 | 212 |
| Notas | `grade_grades` | 557 | 133 |
| | `grade_grades_history` (evidência) | 3 524 | 133 |
| **Quiz** | `quiz_attempts` | **0** | — |
| **Assignment** | `assign_submission` / `assign_grades` | **0** / **0** | — |
| SCORM | `scorm_scoes_track` | 34 614 | 203 |
| Feedback | `feedback_completed` | 190 | 113 |
| Custom Certificate | `customcert_issues` | 113 | 77 |
| Logs | `logstore_standard_log` | 30 127 | — |

**Eventos por dia na origem:**

| 19/10 | 20/10 | 21/10 | 22/10 | 23/10 | 24/10 | 25/10 | **26/10** | **27/10** | **28/10** | 29/10 | 30/10 | 31/10 | 01/11 | 02/11 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 22 674 | 28 304 | 21 777 | 852 | 125 | 0 | 71 | **3 274** | **14 663** | **12 190** | 64 | 40 | 41 | 45 | 42 |

**Conclusões:**

1. **Quiz e Assignment não têm dados no período.** A extracção suporta-os e o ficheiro fica vazio, mas a Fase 4 pode dispensar a recuperação destes componentes, que é a de maior risco (P15).
2. **O instante da cópia ficou localizado no fim de 23/10** (P4). Os eventos de 19 a 23/10 são iguais nas duas BD. Só os 71 eventos de 25/10 na origem ficam antes do período inicial.
3. **15 utilizadores foram criados na origem desde o início do período** (ids 6594 a 6608), e no destino esses ids pertencem a outras pessoas. Ver secção 4 e P16.
4. **Inscrições.** A especificação não as inclui. Um utilizador inscrito na origem durante o período, sem inscrição no destino, não vê o progresso recuperado. O pacote inclui as inscrições como referência, e o diagnóstico conta as criadas no período. O plugin não as cria: ver 5.11.

---

## 4. Achado crítico — divergência de identificadores

A especificação diz que "os identificadores originais deverão, em princípio, ser iguais". Isto **só é verdade para registos criados antes da cópia.**

**Confirmado com dados reais (30/10):** `customcert_issues.id = 51878` é, na origem, a emissão do utilizador `p1042161` (certificado 222). No destino, o mesmo `id` é a emissão de `sandra.chandulal` (certificado 206). O mesmo acontece com os `id` 51879 a 51881.

**Confirmado também nos utilizadores (02/11):** os `id` 6594 a 6608 pertencem a pessoas diferentes em cada instalação. Dos 15 utilizadores criados na origem:

| Situação no destino | Utilizadores |
|---|---|
| Existem, com outro `id` (mapear por `username`) | `p1062252` (`id` 6596 no destino), `p1017706` (`id` 6599 no destino) |
| Não existem | `p1062254`, `p1059368`, `p1042465`, `p1061101`, `p1050305`, `p1045493`, `p1060226`, `p1056881`, `p1046123`, `p1061098`, `p1060386`, `p1045930` |
| Excluir: utilizador de teste | `pedro.edu` |

Todos os reais usam `auth = saml2` (MyZurich) e foram criados na origem durante o período. Copiar o `userid` tal como está atribuiria o progresso à pessoa errada (por exemplo, o de `p1062254` a `pedro2.edu`). **O plugin traduz sempre o `userid` da origem pelo `username`**, em todas as tabelas e também nos logs, e aceita uma lista de utilizadores a excluir.

**Criação das contas em falta (decisão P16, 02/11):**

- Cria-se apenas a conta de quem tem progresso a recuperar. Quem só tem eventos de log não justifica uma conta, e esses eventos são ignorados.
- **Resultado na extracção real (02/11): só `p1042465` precisa de conta** (3 cursos, 1 actividade, 1 resposta de Feedback). Os outros 11 em falta não têm nenhum progresso no período, e `pedro.edu` também não. `p1062252` e `p1017706` têm progresso significativo e já existem no destino, com outro `id`.
- Usa `user_create_user($user, false)`, com `username`, `email`, nomes, `lang` e `timezone` da origem e `auth = saml2`.
- A palavra-passe fica `AUTH_PASSWORD_NOT_CACHED`, porque a autenticação é externa.
- Se o `email` já existir no destino noutra conta, a conta não é criada e o caso fica para decisão manual.
- A criação é registada na auditoria. `timecreated` fica com a data da recuperação, como faz a API.
- **Verificado no código de `auth_saml2` 2021062900 (`classes/auth.php:602`):** com a configuração das duas instalações (`idpattr = Username`, `mdlattr = username`, `anyauth = 0`, `tolower = 0`), o acesso procura a conta pelo `username` e exige `auth = saml2`. Só cria conta nova se não a encontrar. As contas criadas são, por isso, usadas no primeiro acesso. A cada acesso, o plugin actualiza os dados do perfil a partir do MyZurich. O teste real pelo MyZurich fica para a Fase 6.

A partir do momento da cópia, as duas bases de dados geraram `AUTO_INCREMENT` de forma independente. Um registo novo criado na origem a 27/10 (por exemplo `quiz_attempts.id = 50001`) pode ter, no destino, o mesmo `id` atribuído a outro registo criado depois do regresso ao servidor definitivo.

**Consequências obrigatórias para o desenho:**

1. **IDs de estrutura** (utilizador, curso, `course_modules`, `grade_items`, `question`, `feedback_item`, `scorm_scoes`, `customcert`) devem ser iguais. Ainda assim, são validados por identificador natural e por relações (5.1).
2. **IDs de registos de progresso novos** (tentativas, usages, steps, submissões, respostas Feedback, emissões, etc.) **nunca são preservados**:
   - o plugin insere com novo `id`;
   - mantém uma tabela de mapeamento `id_origem → id_destino`;
   - reescreve todas as chaves estrangeiras dependentes.
3. **Registos que já existiam antes da cópia e foram alterados no período** (por exemplo, `course_completions` criado na inscrição e concluído a 27/10) são localizados pela **chave natural** e não pelo `id`.
4. Entidades de estrutura **criadas na origem durante o período** (utilizadores novos, cursos, actividades ou perguntas novas) podem colidir com outras entidades do destino com o mesmo `id`. Estas são classificadas como **inválido / intervenção manual**. O diagnóstico do Extractor deve listar todas as entidades de estrutura com `timecreated` no período.

| Tabela | Chave natural (índice UNIQUE real, quando existe) |
|---|---|
| `course_modules_completion` | UNIQUE (`userid`, `coursemoduleid`) |
| `course_completions` | UNIQUE (`userid`, `course`) |
| `course_completion_crit_compl` | UNIQUE (`userid`, `course`, `criteriaid`) |
| `grade_grades` | UNIQUE (`userid`, `itemid`) |
| `quiz_attempts` | UNIQUE (`quiz`, `userid`, `attempt`) + `uniqueid` UNIQUE → `question_usages.id` |
| `question_attempts` | UNIQUE (`questionusageid`, `slot`) |
| `question_attempt_steps` | UNIQUE (`questionattemptid`, `sequencenumber`) |
| `assign_submission` | UNIQUE (`assignment`, `userid`, `groupid`, `attemptnumber`) |
| `assign_grades` | UNIQUE (`assignment`, `userid`, `attemptnumber`) |
| `scorm_scoes_track` | UNIQUE (`userid`, `scormid`, `scoid`, `attempt`, `element`) |
| `feedback_value` | UNIQUE (`completed`, `item`, `course_id`) |
| `feedback_completed` | sem UNIQUE; natural = (`feedback`, `userid`, `courseid`, `anonymous_response`) quando `multiple_submit = 0` |
| `customcert_issues` | a confirmar no `install.xml`; candidato (`customcertid`, `userid`) + `code` |

**Colisões de numeração de tentativas.** Se um utilizador fez a tentativa 1 de um quiz na origem e, depois do regresso, voltou a fazer "a tentativa 1" no destino, o UNIQUE (`quiz`, `userid`, `attempt`) colide. O mesmo acontece com `scorm_scoes_track.attempt` e `assign_submission.attemptnumber`. São sempre **conflitos** e nunca há fusão automática. A política de renumeração será uma decisão explícita por registo (ver 9).

---

## 5. Esquema real por componente

Notação: `?` = campo pode ser NULL.

### 5.1 Estrutura e correspondência (só leitura, para validação)

| Tabela | Campos usados na correspondência |
|---|---|
| `user` | `id`, `username` (UNIQUE), `idnumber`, `email`, `deleted`, `suspended`, `auth`, `tenantid?`, `timecreated` |
| `course` | `id`, `shortname`, `idnumber`, `fullname`, `enablecompletion`, `containertype`, `timecreated` |
| `course_modules` | `id`, `course`, `module`, `instance`, `section`, `idnumber?`, `completion`, `completiongradeitemnumber?`, `completionview`, `deletioninprogress`, `added` |
| `modules` | `id`, `name` (o `module.id` pode diferir entre instalações; compara-se por `name`) |
| `context` | `id`, `contextlevel`, `instanceid` (UNIQUE `contextlevel`, `instanceid`) |

**Regra de correspondência:** um registo só é compatível se o `id` e o identificador natural coincidirem nas duas instalações:
- utilizador: `username`;
- curso: `shortname`;
- `course_modules`: `course` + nome do módulo + `instance`.

Um `id` igual com natural diferente é **inválido**. Um natural igual com `id` diferente é **intervenção manual**.

### 5.2 Conclusão de actividades

```
course_modules_completion
  id, coursemoduleid, userid, completionstate:int(1), progress:int(3)?, viewed:int(1)?,
  timemodified, timecompleted?, reaggregate
```

- **Detecção no período:** `timemodified` e `timecompleted`.
- **Campos específicos do Totara:** `progress`, `timecompleted` e `reaggregate` não existem no Moodle 3.4.
- **API de escrita:** `\core_completion\helper::write_module_completion()` (`completion/classes/helper.php:261`).
  - Valida que não há duplicação e grava `course_completion_log`.
  - Invalida a cache `core/completion` e marca a cache de progresso de programas.
- **Validação de estados:** `helper::get_module_completion_errors()` e `helper::module_uses_timecompleted()`.

### 5.3 Conclusão de cursos

```
course_completions
  id, userid, course, organisationid?, positionid?, timeenrolled, timestarted, timecompleted?,
  reaggregate, rpl?, rplgrade?, invalidatecache?, status:int(2), renewalstatus?, duedate?
course_completion_crit_compl
  id, userid, course, criteriaid, gradefinal?, unenroled?, rpl?, timecompleted?
course_completion_criteria          (estrutura — só validação)
  id, course, criteriatype, module?, moduleinstance?, courseinstance?, enrolperiod?, timeend?, gradepass?, role?
course_completion_aggr_methd        (estrutura — só validação)
course_completion_history           (Totara)
  id, courseid, userid, timecompleted?, grade?, grademax?, grademin?
course_completion_log               (Totara — auditoria)
  id, courseid, userid?, changeuserid?, description, timemodified
```

- **Detecção:**
  - `course_completions`: `timecompleted` e `timestarted` (não tem `timemodified`).
  - `crit_compl`: `timecompleted`.
  - Fonte auxiliar: `course_completion_log.timemodified`, que regista todas as alterações com descrição.
- **APIs:**
  - `helper::write_course_completion()`, que valida com `get_course_completion_errors()` e devolve `false` se o estado for inválido;
  - `helper::write_criteria_completion($r, $msg, $reaggregation = true)`;
  - `helper::write_course_completion_history()`.
- **`course_completion_log`** é a melhor fonte de "o que mudou no período" para conclusões. Será exportada como evidência e não como dado a importar.

> **Programas e Certificações Totara:** o cliente confirmou a 30/10 que usa apenas cursos (P5), por isso ficam **fora do âmbito**. Como salvaguarda, o diagnóstico do Extractor reporta se existem registos em `prog_completion` ou `certif_completion` alterados no período.

### 5.4 Notas

```
grade_items   id, courseid?, categoryid?, itemname?, itemtype, itemmodule?, iteminstance?, itemnumber?,
              idnumber?, gradetype, grademax, grademin, scaleid?, gradepass, hidden, locked, locktime,
              needsupdate, timecreated?, timemodified?, …
grade_grades  id, itemid, userid, rawgrade?, rawgrademax, rawgrademin, rawscaleid?, usermodified?,
              finalgrade?, hidden, locked, locktime, exported, overridden, excluded, feedback?,
              feedbackformat, information?, informationformat, timecreated?, timemodified?,
              aggregationstatus, aggregationweight?
grade_grades_history  (action, oldid, source, timemodified, loggeduser, + campos de grade_grades)
grade_items_history   (idem para items)
grade_categories      (estrutura — só validação)
```

- **Detecção:** `grade_grades.timemodified`. `grade_grades_history` (índice `userid, itemid, timemodified`) dá a sequência exacta de alterações e o campo `source` (`mod/quiz`, `gradebook`, …).
- `rawgrade` e `finalgrade` são NULL-áveis: **NULL ≠ 0**. A comparação trata NULL como "sem nota".
- **Estratégia:**
  - Notas de actividades (quiz, assign, scorm) **não são copiadas** e sim regeneradas pela API do módulo depois de recuperar os dados de origem (5.5–5.7). Isto mantém o gradebook, os eventos e a conclusão por nota consistentes. Na extracção real, as 557 notas são só de SCORM (400) e totais de curso (157), sem notas manuais, bloqueios nem alterações à mão.
  - Notas manuais (`itemtype = 'manual'`) e overrides usam `grade_update()` (`lib/gradelib.php:62`).
  - O recálculo dos totais usa `grade_regrade_final_grades()` (`lib/gradelib.php:1138`).
  - Os estados `hidden`, `locked`, `overridden` e `excluded` do destino são preservados. Se o destino tiver uma nota bloqueada ou com override, é **conflito**.

### 5.5 Quiz e motor de perguntas

> **Recuperação dispensada pelo cliente a 02/11 (P15):** 0 tentativas no período. O esquema fica documentado e a extracção suporta o componente; o plugin não o escreve e assinala se um pacote trouxer dados destas tabelas.

Motor de perguntas **anterior à reestruturação do Moodle 4.0**: não há `question_bank_entries`, `question_versions` nem `question_references`. `quiz_slots.questionid` aponta directamente para `question.id`.

```
quiz_attempts   id, quiz, userid, attempt, uniqueid(→question_usages.id, UNIQUE), layout, currentpage,
                preview, state, timestart, timefinish, timemodified, timemodifiedoffline, timecheckstate?, sumgrades?
quiz_grades     id, quiz, userid, grade, timemodified
question_usages id, contextid, component, preferredbehaviour            ← sem userid nem datas
question_attempts  id, questionusageid, slot, behaviour, questionid, variant, maxmark, minfraction,
                   maxfraction, flagged, questionsummary?, rightanswer?, responsesummary?, timemodified
question_attempt_steps  id, questionattemptid, sequencenumber, state, fraction?, timecreated, userid?
question_attempt_step_data  id, attemptstepid, name, value?            ← sem datas
quiz_slots, quiz, question          (estrutura — só validação)
```

- **Detecção:** `quiz_attempts.timestart`, `timefinish` e `timemodified`. Tudo o resto é exportado **por dependência**, a partir do `uniqueid` das tentativas seleccionadas, porque `question_usages` e `question_attempt_step_data` não têm datas.
- **Ordem de inserção** (validada pelas FKs reais):
  1. `question_usages` (contexto do cm no destino);
  2. `quiz_attempts` (`uniqueid` = novo usage id);
  3. `question_attempts` (`questionusageid` remapeado);
  4. `question_attempt_steps` (`questionattemptid` remapeado);
  5. `question_attempt_step_data` (`attemptstepid` remapeado).

  Depois disto:
  - `quiz_save_best_grade()` (`mod/quiz/locallib.php:761`) actualiza `quiz_grades` e o gradebook;
  - `completion_info::update_state()` actualiza a conclusão.
- **Não existe API pública para "importar" uma tentativa já concluída.** A escrita directa nestas 5 tabelas é tecnicamente justificada (regra 6 da especificação). Fica dentro de uma transacção por tentativa, com validação prévia de que:
  - cada `questionid` existe no destino com o mesmo `qtype`, `stamp` e `version`;
  - os `slot` coincidem com `quiz_slots` do destino.
- **Validação estrutural:** `question.stamp` + `question.version` identificam a mesma versão da pergunta. Se a pergunta foi editada no destino depois da cópia, é **conflito**.
- **Pontos a decidir:**
  - tentativas em `state = 'inprogress'` ou `'overdue'` no fim do período;
  - respostas `qtype_essay` com anexos (ficheiros fora do âmbito, mas a referência fica em `step_data`).

### 5.6 Assignment

> **Recuperação dispensada pelo cliente a 02/11 (P15):** 0 submissões e 0 avaliações no período. Mesmo tratamento do Quiz.

```
assign_submission   id, assignment, userid, timecreated, timemodified, status?, groupid, attemptnumber, latest
assign_grades       id, assignment, userid, timecreated, timemodified, grader, grade?, attemptnumber
assign_user_flags   id, userid, assignment, locked, mailed, extensionduedate, workflowstate?, allocatedmarker
assign_user_mapping id, assignment, userid           (blind marking)
assignsubmission_onlinetext  id, assignment, submission(→assign_submission), onlinetext?, onlineformat
assignsubmission_file        id, assignment, submission, numfiles     ← metadado a preservar (sem ficheiros)
assignfeedback_comments      id, assignment, grade(→assign_grades), commenttext?, commentformat
assignfeedback_file          id, assignment, grade, numfiles
```

- **Detecção:** `assign_submission.timemodified` e `assign_grades.timemodified`. `onlinetext`/`comments` vêm por dependência.
- **Atenção:**
  - `assign_submission` é normalmente criada com `status = 'new'` antes da submissão. No destino, a linha pode já existir (anterior à cópia), o que corresponde ao caso **alterado**, localizado pela chave natural.
  - `latest = 1` só pode existir numa tentativa por utilizador.
- **`assignsubmission_file.numfiles`** é exportado para identificar submissões que tinham ficheiros. O plugin **não** cria registos em `files`, e o relatório assinala estas submissões como "submissão com ficheiros não recuperados".
- **APIs:**
  - avaliação: `assign::update_grade()` (`mod/assign/locallib.php:2391`), que trata do gradebook;
  - submissão: não há API pública para submissão com data passada, pelo que se usa escrita directa validada seguida de `gradebook_item_update`/`update_state`.

### 5.7 SCORM

Estrutura **pré-Moodle 4.3**: não há `scorm_attempt`, `scorm_scoes_value` nem `scorm_element`. O tracking está todo em `scorm_scoes_track`.

```
scorm_scoes_track  id, userid, scormid, scoid, attempt, element, value, timemodified
scorm_aicc_session id, userid, scormid, hacpsession, scoid?, scormmode?, scormstatus?, attempt?, …, timecreated, timemodified
scorm, scorm_scoes  (estrutura — validar scorm_scoes.identifier + scorm.revision/sha1hash)
```

- **Detecção:** `timemodified`. Uma tentativa (`userid`, `scormid`, `attempt`) é exportada **completa**, com todos os elementos (`cmi.*`), se qualquer elemento foi alterado no período. Assim não se misturam tentativas.
- **Validação:** `scorm_scoes.identifier` e `scorm.sha1hash` iguais nos dois lados. Se o pacote SCORM foi substituído no destino, os `scoid` podem já não corresponder, o que é **incompatível**.
- **Escrita directa, em vez de `scorm_insert_track()`** (`mod/scorm/locallib.php:473`): esta função grava a data de hoje e, em cada `lesson_status` ou `score.raw`, chama `scorm_update_grades()` (`mod/scorm/lib.php:669`), que actualiza a conclusão com a data de hoje. Isso apagaria as datas da origem e faria o próprio trabalho recuperado parecer *Destino mais recente*. Tal como a API, o `x.start.time` existente nunca é alterado.
- **Notas:** calculadas depois de recuperadas as conclusões (10.4). `completion_info::update_state()` não faz nada se o estado já for o mesmo, mas as linhas de nota têm de ser reservadas antes do recálculo, senão a conclusão perde a data.

### 5.8 Feedback

```
feedback_completed  id, feedback, userid, timemodified, random_response, anonymous_response, courseid
feedback_value      id, course_id, item(→feedback_item), completed(→feedback_completed), tmp_completed, value
feedback_completed_history / feedback_value_history   (arquivo, com timearchived/idarchived)
feedback_completedtmp / feedback_valuetmp             (respostas em curso — NÃO exportar)
feedback, feedback_item, feedback_sitecourse_map      (estrutura — só validação)
```

- **`feedback_tracking` não existe nesta versão.** Foi removida na linha Moodle 3.1. A especificação refere-a, mas não há nada a extrair.
- **Detecção:** `feedback_completed.timemodified`. Não há `timecreated`. `feedback_value` vem por dependência (`completed`).
- **Comportamento verificado no código** (`mod/feedback/lib.php:1974`): ao editar uma resposta existente, o Totara **apaga e volta a inserir** todos os `feedback_value` dessa resposta (novos `id`) e actualiza `timemodified`. A comparação de valores é feita por (`completed` natural, `item`) e nunca por `feedback_value.id`.
- **Respostas anónimas** (`anonymous_response = 1`): o `userid` fica gravado para utilizadores autenticados (`mod/feedback/classes/completion.php:459-460`), mas o próprio Totara não o usa para identificar a resposta (`completion.php`, `find_last_completed`). Para não haver duplicação, a correspondência usa (`feedback`, `userid`, `courseid`, `timemodified`, hash dos valores). Os relatórios de auditoria **nunca mostram os valores** de respostas anónimas e mostram apenas contagens.
- **API:** não há API de importação. É necessária escrita directa em `feedback_completed` + `feedback_value`, seguida de `completion_info::update_state()` quando `completionsubmit = 1`.

### 5.9 Custom Certificate

Versão **2016052321** ("3.1 release"), instalada no ambiente de teste a 30/10/2026 a partir do código do cliente.

```
customcert            id, course, templateid, name, intro?, introformat, requiredtime, verifyany,
                      emailstudents, emailteachers, emailothers?, protection, timecreated, timemodified
customcert_templates  id, name?, contextid(→context), timecreated, timemodified
customcert_pages      id, templateid, width, height, leftmargin, rightmargin, sequence?, timecreated, timemodified
customcert_elements   id, pageid, name, element, data?, font?, fontsize?, colour?, posx?, posy?, width?,
                      refpoint?, sequence?, timecreated, timemodified
customcert_issues     id, userid, customcertid, code:char(40)?, emailed:int(1), timecreated
```

**Comportamento verificado no código:**

- **Uma emissão por (`userid`, `customcertid`)**, garantida apenas pela aplicação. **Não há índice UNIQUE.**
  - `view.php:151` só insere se não existir;
  - o upgrade 2016052310 apagou duplicados;
  - os elementos `code` e `date` fazem `get_record(..., MUST_EXIST)`.

  **Uma emissão duplicada impede a geração do PDF desse utilizador** (excepção de múltiplos registos). O plugin nunca insere se já existir uma emissão no destino para o par.
- **Código:** `certificate::generate_code()` gera 10 caracteres aleatórios e garante unicidade global só pela aplicação. Antes de inserir, o plugin confirma que o `code` da origem não existe no destino noutra emissão.
- **Verificação** (`verify_certificate.php`): é feita por `code` + `customcertid`, ou só por `code` com `verifyany`. Preservar o `code` mantém válidos os certificados já descarregados pelos utilizadores.
- **O PDF não é guardado.** É gerado em cada download (`template::generate_pdf`) com dados lidos do destino nesse momento:
  - data da emissão (`issues.timecreated`);
  - data de conclusão (`MAX(course_completions.timecompleted)`);
  - notas do gradebook;
  - campos do utilizador.

  Por isso, **o certificado só fica correcto depois de recuperadas as conclusões e as notas** (confirma a ordem da secção 10).
- **Emissão automática pela tarefa `email_certificate_task`: risco descartado (verificado a 30/10).** A tarefa só emite para certificados com `emailstudents`, `emailteachers` ou `emailothers` definido. Nos 227 certificados do destino estes campos estão todos a 0 ou vazios, por isso a tarefa não cria emissões. **As 4 emissões do destino foram criadas por utilizadores** que descarregaram o certificado no destino na noite de 28/10. O plugin preserva o `emailed` da origem na mesma, por segurança.
- **Conflito real já identificado:** o utilizador `p1044867` (userid 4959), certificado 244, tem **duas emissões com códigos diferentes**:

  | | Origem | Destino |
  |---|---|---|
  | `code` | `YGK4Y0qdpx` | `k4UoF1XK6l` |
  | Data | 26/10 22:23 | 28/10 22:27 |

  Não se pode inserir a da origem (ficaria duplicado e o PDF deixaria de abrir), e o utilizador pode ter descarregado os dois PDFs. O caso é classificado como **conflito com decisão obrigatória** (P12). Como evidência para a decisão, o relatório mostra os eventos de visualização e download do certificado no logstore das duas instalações.

  Os outros 3 utilizadores com emissão no destino (`sandra.chandulal`/206, `p1042356`/215, `tiago.semedo`/201) só podem ser verificados com a lista completa das 113 emissões da origem.
- **Personalização do cliente em `view.php` (linhas 49-80, não existe no customcert original):** quando `requiredtime > 0`, o acesso ao certificado exige duas condições:
  1. tempo no curso calculado a partir de `logstore_standard_log` (`certificate::get_course_time`);
  2. soma de `cmi.core.total_time` / `cmi.total_time` do SCORM, via `block_dedication_manager::sumTime()`.

  O `block_dedication` (2020041000, "Moodle 3.x+") foi instalado no ambiente de teste a 30/10. O `view.php` faz `require_once` de `blocks/dedication/dedication_lib.php` incondicionalmente. `sumTime()` soma o `total_time` de **todas** as tentativas SCORM do utilizador no curso.

  **223 dos 227 certificados do destino têm `requiredtime > 0`** (30 a 240 minutos). A verificação corre **antes** de olhar para a emissão, por isso um utilizador com emissão recuperada **não consegue abrir o certificado** se faltarem no destino:
  - os eventos do logstore do período (tempo no curso);
  - ou os registos SCORM `cmi.core.total_time` / `cmi.total_time`.

  **Recomendação:** incluir os eventos de `logstore_standard_log` do período como componente recuperável (P8). Esta tabela só recebe inserções e nenhuma outra tabela referencia os seus `id`, por isso o risco de inserção é baixo. Os eventos são filtrados aos utilizadores e cursos do pacote.
- **Ficheiros de elementos:** `bgimage`, `image` e `digitalsignature` dependem de ficheiros na tabela `files`. O diagnóstico compara `contenthash` + `filearea` + `itemid` nas duas instalações, além de `customcert_elements` (`element`, `data`, posições) por template.
- **Estado de uma emissão recuperada:** "pronta" só depois de gerar o PDF com sucesso, pela API `template::generate_pdf(false, $userid, true)`, sem envio ao browser. Caso contrário fica "necessita intervenção".

### 5.10 Logs e evidências

**`logstore_standard_log` é um componente recuperável (decisão P8, 30/10).** É necessário para o tempo no curso exigido pelos certificados (5.9) e para os relatórios de actividade.

| Aspecto | Regra |
|---|---|
| Âmbito | Eventos da origem com `timecreated` no período, de utilizadores existentes no destino. Os 1 234 eventos sem utilizador real (`userid` 0, -1 e -10: visitantes, notas por tarefa, emails falhados) são ignorados: o tempo no curso conta-se por utilizador |
| `relateduserid`, `realuserid`, `contextinstanceid` de utilizador | Traduzidos por `username` na escrita. Sem correspondência fica `NULL`. Na extracção real, 320 eventos têm `relateduserid` ≥ 6594 (ids que no sv2 são de outras pessoas). Os 23 com `relateduserid` fora do pacote são eventos sem utilizador real, que já são ignorados |
| `id` | Novo `id` no destino (nenhuma tabela referencia `logstore_standard_log.id`) |
| `contextid`, `contextinstanceid`, `courseid` | Validados no destino (estruturas anteriores à cópia). Contexto inexistente → evento ignorado e reportado |
| `objectid` | Traduzido quando se confirma que é o mesmo registo. Caso contrário fica `NULL` (campo NULL-ável), para não apontar para um registo alheio. Regras em 10.5 |
| Idempotência | Uma operação por evento da origem em `local_progressrecovery_ops`. A comparação procura no destino por (`eventname`, `userid`, `contextid`, `timecreated`), mas a escrita não, porque há eventos iguais no mesmo segundo (10.5) |

Restantes tabelas — **só evidência, nunca importadas:**

| Tabela | Uso |
|---|---|
| `course_completion_log` | Histórico de alterações de conclusão |
| `grade_grades_history` | Sequência de alterações de notas |
| `block_totara_stats` (`userid`, `timestamp`, `eventtype`) | Estatísticas Totara — avaliar se devem ser regeneradas |

### 5.11 Inscrições por grupos globais dinâmicos (decisão P17, 02/11)

**Modelo do cliente, a seguir na recuperação:**

- Os cursos de 2026 são atribuídos por grupos globais dinâmicos.
- Há duas fichas de inscrição, uma para parceiros e outra para internos (Zurich). Cada ficha é um curso com apenas um inquérito.
- Preencher a ficha coloca o utilizador no grupo dinâmico que dá acesso aos cursos.
- "Clientes vulneráveis" audiência geral: entra quem tem a ficha preenchida e não está inscrito em "Clientes vulneráveis" específico, que é só para certos utilizadores.

**Consequência: o plugin não cria inscrições.** Recupera a ficha e deixa o mecanismo do Totara colocar o utilizador nos grupos e nos cursos.

**Regras reais de 2026, lidas no destino a 02/11** (operadores confirmados no código: `equal` 0 = contém, 1 = não contém; `coursecompletionlist` 40 = concluiu todos; `courseenrolmentlist` 0 = não inscrito em nenhum):

| Grupo (`id`) | Quem entra | Membros | Cursos |
|---|---|---|---|
| Parceiros, Ficha 2026 (28) | Email sem `@zurich.com` nem `na@na.na`, ou `username` com `eduardo.perdido` | 5 550 | 1 |
| Parceiros, Pós Ficha 2026 (29) | Email sem `@zurich.com` **e** concluiu o curso **174** | 1 026 | 20 |
| Internos, Ficha 2026 (30) | Email com `@zurich` | 948 | 1 |
| Internos, Pós Ficha 2026 (31) | Email com `@zurich.com` **e** concluiu o curso **163** | 329 | 18 |
| Clientes Vulneráveis, Geral 2026 (33) | Email com `@zurich.com`, concluiu o curso **163** **e** não está inscrito no curso **164** | 307 | 1 |

Os grupos de anos anteriores (21, 22, 24 e 26) seguem o mesmo modelo, com os cursos 138, 139 e 140.

**Conclusões:**

- As fichas de 2026 são os cursos **174** (parceiros) e **163** (internos).
- O plugin não fixa estes números: lê no destino os cursos usados nas regras `coursecompletionlist`, e trata-os como fichas.
- **Em todas as regras a conclusão é uma condição positiva, por isso recuperar uma conclusão só pode acrescentar membros, nunca retirar.** O risco de anular inscrições levantado nesta secção fica afastado para a recuperação de conclusões.
- A única condição negativa é a inscrição no curso 164, que o plugin não cria. **Confirmado a 02/11:** o curso 164 ("Clientes Vulneráveis 2026") só tem inscrição manual activa, por isso nenhum grupo dá acesso a ele. Os outros cursos confirmam as regras: 163 ("Ficha de Inscrição 2026") pelo grupo 30, 174 ("Parceiros de Negócio - Ficha de Inscrição 2026") pelo grupo 28 e 205 ("Clientes Vulneráveis - Audiência geral 2026") pelo grupo 33.
- **As inscrições da origem confirmam o modelo:** 911 inscrições criadas no período, de 57 utilizadores (cerca de 16 por utilizador, o número de cursos dos grupos Pós Ficha).
- **Linhas só de inscrição não são importadas:** das 1 207 linhas de `course_completions` no período, 827 só têm `timeenrolled` no período, sem início nem conclusão. Quando o utilizador entra no grupo, o próprio Totara cria essas linhas, com a inscrição do destino.
- `unenrolaction = 0` confirma que sair de um grupo anula a inscrição, e `removeoldmembers = 1` nos grupos de 2026. É por isso que o plugin não altera emails nem inscrições de contas existentes.
- **Na extracção real, as 911 inscrições criadas no período são todas `cohort`, nenhuma manual.** O caso de uma inscrição manual no curso 164 feita na origem não ocorreu. Fichas concluídas no período: 41 no curso 174 e 4 no curso 163.

**Verificado no código:**

| Aspecto | Comportamento |
|---|---|
| Regra de conclusão de curso | `cohort_rule_sqlhandler_completion_list_course` lê `course_completions` com `timecompleted > 0`. Não depende de eventos, por isso uma conclusão gravada com `core_completion\helper` é reconhecida |
| Sincronização | Tarefas `totara_cohort\task\update_cohort_task` e `enrol_cohort\task\sync_members`, ambas de hora a hora |
| Sincronização imediata | Tarefa ad hoc `sync_dynamic_cohort_task` para um grupo (actualiza os membros e as inscrições). É o que o plugin usa, porque não depende de um pedido HTTP longo |
| Saída de um grupo | `enrol_cohort` aplica `unenrolaction`, que por omissão **anula a inscrição** |

**Sequência de recuperação:**

1. Recuperar Feedback, conclusão de actividade e conclusão de curso das **fichas**.
2. Colocar em fila a sincronização dos grupos que dependem das fichas recuperadas, e esperar pelas inscrições sem bloquear a interface.
3. Recuperar o restante progresso dos utilizadores.
4. Listar no relatório quem continua sem inscrição, sem a forçar.

**Salvaguarda:** mesmo com as regras analisadas, a Fase 6 compara os membros dos grupos e as inscrições antes e depois, na cópia de homologação.

---

## 6. Diferenças face à especificação original

| # | Especificação | Realidade verificada | Impacto |
|---|---|---|---|
| D1 | IDs "em princípio iguais" | Iguais só antes da cópia; registos novos divergem | Remapeamento obrigatório (4) |
| D2 | `feedback_tracking` | Não existe | Removida do pacote |
| D3 | `scorm_scoes_track` + "tabelas de tentativas" | Só `scorm_scoes_track` (+ `scorm_aicc_session`) | Mais simples |
| D4 | "motor de perguntas" genérico | Modelo pré-4.0 (`question` + `quiz_slots.questionid`) | Validação por `stamp` + `version` |
| D5 | `course_completions` com `timemodified` | Não existe; Totara usa `status`, `reaggregate`, `invalidatecache` | API `core_completion\helper` |
| D6 | — | `course_completion_history`, `course_completion_log` (Totara) | Usar como evidência e auditoria |
| D7 | — | Programas e Certificações dependem de conclusões de cursos | Pendente P5 |
| D8 | `customcert` "versão actual" | 2016052321; sem UNIQUE em `customcert_issues`; PDF gerado dinamicamente | Nunca duplicar emissões; recuperar após conclusões/notas |
| D11 | — | `view.php` do customcert personalizado (logs + tempo SCORM + `block_dedication`); 223/227 certificados com `requiredtime` | Logs do período necessários para aceder aos certificados (P8) |
| D12 | — | `email_certificate_task` pode emitir certificados automaticamente | Verificado: inactivo na prática (sem emails configurados) |
| D14 | Quiz e Assignment no âmbito | 0 registos no período | Recuperação dispensável (P15) |
| D15 | Utilizadores "iguais nas duas instalações" | 15 criados na origem desde 26/10 | Correspondência por `username`; política a decidir (P16) |
| D16 | Inscrições fora da especificação | Sem inscrição no destino, o progresso fica inacessível | Incluídas no pacote (P17) |
| D13 | Período 26/10–28/10 sem escrita no destino | Destino em uso desde 28/10 ~21:50; origem com cron activo a 30/10 | Janela de sobreposição; período real a confirmar (P4, P13) |
| D9 | Período exclusivo na origem | Destino tem 4 emissões no período | Política de conflitos obrigatória |
| D10 | Estrutura `lang/pt` | O Totara exige `lang/en` (idioma base obrigatório) | Criar `en` + `pt` |

---

## 7. Arquitectura do plugin no Totara 19 (convenções confirmadas)

| Aspecto | Convenção Totara 19 |
|---|---|
| Base | Moodle 3.4.9 com extensões Totara; `$plugin->requires = 2025070800` |
| AMD | `amd/src/*.js` **compilado** para `amd/build/*.min.js` (grunt); sem o build, o JS não carrega com cache activa. Os plugins locais existentes (`local/categoryfinder`, `local/crosstenant`) seguem este padrão |
| Tarefas assíncronas | `\core\task\adhoc_task` (`lib/classes/task/adhoc_task.php`) — opcional; o fluxo principal é AJAX por lotes |
| Bloqueios | `\core\lock\lock_config::get_lock_factory('local_progressrecovery')` (factory MySQL ou DB record), em conjunto com a tabela própria de locks para estado visível na UI |
| Ficheiros privados | `$CFG->dataroot` / File API, área `local_progressrecovery/package`, contexto de sistema; nunca no webroot |
| AJAX | `db/services.php` + external functions (`core/ajax`), com `require_capability` + `validate_context` + `sesskey` implícito |
| Logs | Eventos próprios (`classes/event/*`) + tabela de auditoria |

A Fase 2 usa o `version.php` e o `settings.php` corrigidos, e não precisa de JavaScript próprio, por isso ainda não há `amd/`.

As tabelas do plugin têm nomes completos: o limite do XMLDB no Totara 19 é de 48 caracteres. Na Fase 2 existem só `local_progressrecovery_packages` e `local_progressrecovery_audit`; as restantes são criadas com `db/upgrade.php` nas fases que as usam.

---

## 8. Formato do pacote (proposta v1)

Mantém a estrutura indicativa da especificação, ajustada ao esquema real. Cada linha NDJSON é um envelope:

```json
{"t":"quiz_attempts","sid":50001,"role":"changed","nk":{"quiz":"12","userid":"345","attempt":"2"},"h":"sha256…","d":{ …linha… }}
```

| Campo | Significado |
|---|---|
| `t` | tabela de origem (sem prefixo) |
| `sid` | `id` na origem — só para mapeamento, nunca para inserção |
| `role` | `changed` (alterado no período), `dependency` (exportado por relação), `structure` (referência para validação) ou `evidence` (só evidência, nunca importado) |
| `nk` | chave natural |
| `h` | SHA-256 do conteúdo normalizado, sem o `id` nem chaves para registos sem id estável (lista `hash_exclude` no manifesto) |
| `d` | linha completa, ou lista branca de colunas nas estruturas; valores sempre como texto ou `null` |

- `manifest.json`: campos da especificação 3.7, mais `siteidentifier`, prefixo, `period_start_epoch` e `period_end_epoch`.
- `integrity.json`: SHA-256 por ficheiro.
- `signature.json`: HMAC-SHA256 de `TRP1\n<sha256 manifest.json>\n<sha256 integrity.json>`, com segredo partilhado configurado nas duas aplicações.
- As entidades referenciadas seguem em ficheiros próprios (`users/`, `courses/`, `enrolments/` e os `instances` de cada módulo), com os identificadores naturais. O destino valida cada `id` por aí, sem repetir essa informação em cada linha.
- **Nunca exportados:** `user.password`, `user.secret`, `quiz.password`, `enrol.password`, conteúdo de ficheiros.
- Inclui `logs/logstore.ndjson` com os eventos do período (decisão P8, regras em 5.10).
- O pacote **não contém** SQL, nem tabelas fora de uma lista branca, nem ficheiros binários.

---

## 9. Política de comparação e conflitos (decidida pelo cliente a 30/10/2026)

**Regra geral:** se o destino já tiver conclusões, tentativas ou outros dados novos, **mantêm-se os do destino** e a origem **apenas preenche o que está em falta**.

**Unidade de decisão** (nunca se misturam dados de origem e destino dentro da mesma unidade):

| Componente | Unidade |
|---|---|
| Conclusão de actividade | (utilizador, `course_modules`) |
| Conclusão de curso | (utilizador, curso) |
| Notas manuais | (utilizador, `grade_items`) |
| Quiz | (utilizador, quiz) — todas as tentativas |
| Assignment | (utilizador, assign) |
| SCORM | (utilizador, scorm) — todas as tentativas |
| Feedback | (utilizador, feedback) |
| Custom Certificate | (utilizador, customcert) |

**"Dados novos no destino"** são registos da unidade com timestamp ≥ **instante da cópia** (24/10/2026 00:00, `1792796400`) que **não** sejam idênticos aos da origem. Registos anteriores à cópia existem nos dois lados com o mesmo hash e são classificados primeiro como idênticos.

| Situação | Classificação | Acção |
|---|---|---|
| Igual nos dois lados (hash) | Idêntico | Ignorar |
| Não existe no destino; dependências válidas | Novo | Criar (após aprovação) |
| Existe no destino só com dados anteriores ao período; origem com progresso no período | Alterado | Completar com os dados da origem (após aprovação) |
| Destino com dados novos na mesma unidade | Destino mais recente | **Manter destino**; origem ignorada e listada no relatório |
| Nota bloqueada ou com override no destino | Destino mais recente | Manter destino |
| Dependência inexistente/inconsistente (utilizador, cm, pergunta, item) | Inválido | Bloquear |
| Situação não coberta pelas regras anteriores | Conflituoso | Decisão manual |

**O destino pode mudar depois da comparação:** a recuperação volta a classificar cada registo no momento da escrita, com estas regras. Se deixou de ser *Novo* ou *Alterado*, é saltado e listado.

**Conclusão na origem, por concluir no destino:** a conclusão é o que falta, por isso é preenchida, mesmo que o utilizador tenha reaberto a actividade ou iniciado o curso depois da cópia. Aplica-se a `course_modules_completion` e `course_completions`. Só uma conclusão no destino, ou uma nota bloqueada ou com override, impede o preenchimento.

**Consequências:**

- **Não há renumeração de tentativas.** Se o destino tem tentativas novas para (utilizador, quiz) ou (utilizador, scorm), as tentativas da origem dessa unidade não são recuperadas. Se não tem, os números da origem não colidem, porque as tentativas anteriores à cópia são as mesmas nos dois lados. Isto resolve P7.
- **Custom Certificate (P12):** se o destino já tem emissão para (utilizador, customcert), essa emissão mantém-se. O código da origem deixa de ser verificável e é listado no relatório para o cliente poder comunicar com os utilizadores. Caso conhecido: `p1044867`, certificado 244.
- **Logs (P8):** os eventos da origem no período são sempre acrescentados. As regras de inserção estão em 5.10.

---

## 10. Ordem de recuperação

1. **Tradução de utilizadores** por `username`, excluindo os utilizadores de teste. Criação dos que faltam, conforme P16.
2. **Fichas de inscrição:** Feedback → conclusão de actividade → conclusão de curso.
3. **Sincronização dos grupos dinâmicos** e confirmação das inscrições (5.11).
4. **SCORM:** só as linhas das tentativas, com as datas da origem.
5. **Feedback** dos restantes cursos.
6. **Conclusão de actividades**, com as datas da origem.
7. **Notas:** `scorm_update_grades` e notas manuais → `grade_regrade_final_grades`.
8. **Conclusão de critérios e de cursos**, com as datas da origem.
9. **Logs**, necessários para o tempo mínimo dos certificados.
10. **Custom Certificate**, que depende das conclusões, das notas, do SCORM e dos logs.

> Revisto a 03/11: as conclusões são repostas com os valores da origem antes de se regenerarem as notas. Se as notas viessem primeiro, as APIs dos módulos criavam as conclusões com a data de hoje, e a data real perdia-se. As fichas vêm antes de tudo, porque dão acesso aos cursos. A Fase 4 valida isto componente a componente.

---

### 10.1 Primeira etapa da Fase 4: contas e fichas (plugin v0.3.0)

| Decisão | Porquê |
|---|---|
| Opção `allowrecovery`, desligada por omissão, e confirmação da cópia de segurança em cada execução | Instalar o plugin nunca permite, por si só, alterar dados académicos |
| Uma transacção e uma operação por registo, em `local_progressrecovery_ops`, com índice único (pacote, tabela, registo) | Interromper ou repetir não duplica nada. Uma falha fica registada e os outros continuam |
| Conclusões pelo `core_completion\helper`, com os dados e as datas da origem | Valida o estado, grava `course_completion_log` e limpa as caches. `completion_info::update_state()` usaria a data de hoje |
| Sem eventos de conclusão | A regra de grupo lê `course_completions` (5.11). Programas e certificações estão fora do âmbito (P5) |
| Curso já existente: mantém inscrição, posição e organização do destino, e recebe início, conclusão, estado e RPL da origem. Curso novo: posição e organização a `NULL` | Esses ids não são validados pela comparação |
| Curso concluído: `reaggregate = 0` | Uma reagregação pendente não pode alterar a conclusão recuperada |
| Feedback: escrita directa em `feedback_completed` e `feedback_value`, igual a `feedback_save_tmp_values()` | Não há API de importação. Uma resposta identificada actualiza a mesma linha, mesmo com `multiple_submit = 1`: `find_last_completed()` procura sempre a última |
| Grupos a sincronizar: os das regras das fichas recuperadas e os que dão acesso aos cursos com inscrição pendente | É preciso abranger o grupo da ficha para as contas novas, que não depende de uma conclusão. O cron executa as tarefas |
| `previous` guarda a linha anterior das conclusões, mas nunca respostas de inquéritos | Privacidade. As 4 respostas identificadas substituídas repõem-se pela cópia de segurança |

### 10.2 Segunda etapa da Fase 4: tentativas SCORM (plugin v0.4.0)

**A primeira etapa foi validada a 03/11 no sv1:** 3 contas, 45 + 4 respostas, 45 conclusões de actividade, 45 critérios e 3 + 47 conclusões de curso, sem saltados nem falhados. Os grupos subiram +3, +41, +4 e +4, e as inscrições pendentes baixaram de 85 para 1 (`filipa.cardoso`, como previsto). As fichas foram conferidas no Totara.

| Decisão | Porquê |
|---|---|
| Só linhas de `scorm_scoes_track`, sem notas nem conclusões | Ver a ordem revista na secção 10 |
| Uma transacção por lote de 500. Se falhar, repete-se linha a linha | São cerca de 34 000 linhas pequenas. A falha fica isolada na linha que a causou |
| Uma unidade pode ocupar vários lotes (214 das 643 não estão seguidas no pacote) | `scorm_source_keys()` lê o pacote todo em cada pedido, para o trabalho já recuperado não contar como novo no destino |
| Utilizador sem inscrição (`filipa.cardoso`): recuperado na mesma | O progresso fica pronto, e o caso continua listado como pendente |
| `previous` guarda só a data anterior, nunca o valor | Os resultados nunca guardam conteúdo SCORM. As linhas actualizadas repõem-se pela cópia de segurança, e as inseridas removem-se pelo `id` gravado |

A página de recuperação mostra agora só o que falta. O plano antigo repetia os números da comparação mesmo depois de recuperado.

**Validada a 03/11 no sv1:** 33 949 linhas criadas e 470 actualizadas, sem saltados nem falhados, e `p1062252` conferido no relatório SCORM. Falta repetir a comparação, para confirmar que estas linhas e as fichas passam a *Idêntico* (a comparação no ecrã ainda era a de 02/11).

**`p1061101`:** o sv1 tem outra conta com o mesmo email (`email_exists`). Na origem tem só 1 evento de log e 1 linha só de inscrição, sem progresso, por isso não é preciso decidir nada e a conta não é criada. Volta a ser avaliado com a cópia do sv2.

**Comparação repetida a 03/11 no sv1, antes da etapa 3:** coincide com o previsto (actividades 45 / 562 / 35, cursos 95 / 770, SCORM 44 972 / 0 / 0, Feedback 49 / 141 / 0, eventos 22 867 / 7 260), o que confirma as etapas 1 e 2. Nos cursos, todas as 330 linhas por recuperar passaram a *Alterado*, porque a inscrição por grupo já as criou.

**Corrigido na v0.5.1:** as inscrições pendentes só contavam registos ainda por recuperar, por isso `filipa.cardoso` desapareceu da lista depois de recuperado o SCORM, embora continue sem inscrição. O progresso do período já presente no destino passa a contar.

### 10.3 Terceira etapa da Fase 4: Feedback e conclusões (plugin v0.5.0)

As quatro tabelas ficam juntas porque usam o mesmo código de escrita, já validado no sv1 com as fichas, e têm de ficar concluídas antes das notas (secção 10). O resultado continua a ser apresentado por tabela.

| Decisão | Porquê |
|---|---|
| Todos os cursos, sem limite. As fichas já feitas são saltadas pelas operações | Um só caminho de código, sem listas de cursos a manter |
| Sem sincronização de grupos | As regras dos grupos só dependem das fichas (5.11), já recuperadas |
| Curso em que a inscrição por grupo já criou a linha: fica *Alterado* e mantém a data de inscrição do destino | A data de inscrição pertence ao destino, como na etapa 1 |

**Validada a 03/11 no sv1:** quadro acumulado igual ao previsto, sem saltados nem falhados. A comparação repetida deu 642, 865 e 190 Idênticos, com `filipa.cardoso` como única inscrição pendente. As conclusões foram conferidas no relatório do curso.

### 10.4 Quarta etapa da Fase 4: notas (plugin v0.6.2)

**Primeira execução no sv1 (v0.6.0, 03/11):** as 393 notas de SCORM correram bem, e os 154 totais de curso falharam com `cannotbenull`, porque a chamada ao recálculo não indicava o item. Nada foi escrito por essas operações, e a v0.6.1 corrige a chamada. O simulador dos testes passa a exigir o mesmo que o Totara.

**Comparação depois da v0.6.1:** as notas ficaram certas (547 Idênticos), mas 107 conclusões de actividades perderam a data da origem (106 *Destino mais recente* e 1 *Alterado*), e 106 linhas de conclusão de cursos também mudaram.

**Causa:** com a primeira nota de um utilizador num curso, o recálculo do total cria linhas vazias para as outras actividades (`grade_category::aggregate_grades()`). `grade_grade::insert()` chama `notify_changed()`, e a nota vazia devolve a actividade concluída por nota a incompleta, com a data de hoje. A nota real volta a concluí-la, também com a data de hoje, e o critério do curso é regravado (`completion_criteria_activity::review()`). A contagem feita com o pacote prevê exactamente 107 casos. Numa produção, isto criaria também eventos `module_completion` e notificações de actividade concluída.

**Correcção (v0.6.2):** antes de calcular, `reserve_grade_rows()` cria as linhas em falta do utilizador no curso, por escrita directa e sem notificar a conclusão. Assim o recálculo não encontra nada a criar. A linha fica igual à que o Totara criaria.

| Decisão | Porquê |
|---|---|
| Nota do SCORM: `scorm_get_user_grades()` seguida de `scorm_grade_item_update()`, com `usermodified`, `dategraded` e `datesubmitted` da origem | `scorm_update_grades()` usaria a data de hoje e o administrador como autor, e chamaria `scorm_set_completion()`, que não é preciso |
| Totais de curso: `grade_regrade_final_grades()` por utilizador, com o item do curso como item actualizado (obrigatório quando se indica o utilizador), depois de todas as actividades | Os totais dependem delas. O cálculo respeita as notas bloqueadas e alteradas à mão no destino |
| Os totais não são reclassificados na escrita | A recuperação das actividades já os recalculou, com data recente, e pareceriam *Destino mais recente* |
| A data do total de curso fica a da recuperação | É o que `grade_category` grava (`time()`). Só se vê no livro de notas: a data dos certificados vem da conclusão do curso (`dateitem = -2`) |
| Nota calculada comparada com a da origem. Se diferir, fica a do Totara e o registo é assinalado como *Diferente da origem* | Nunca se esconde uma diferença, e a nota mostrada corresponde às tentativas que existem |
| Os eventos `user_graded` disparam normalmente | São da API. Criam linhas de log com a data da recuperação, e o `bi_intellidata` observa-os. Rever antes da produção se isto afecta os relatórios de BI (P25) |

**Repetição no sv1 a partir da cópia (v0.6.2, 03/11):** 547 notas tratadas, sem saltados, falhados nem diferentes da origem. A comparação ficou como previsto: actividades 642 e 3 ignoradas, cursos 865 e 827, notas 547 e 10, SCORM 44 972 e 223, Feedback 190, e **0 *Destino mais recente***, por isso a correcção resolveu as 107 conclusões.

**Divisão dos rótulos:** o resultado mostra 478 criados e 69 actualizados, e o plano tinha 442 *Novos* e 105 *Alterados*. O total é o mesmo. Uma linha vazia no destino, sem nota nem data, é tratada como nota nova e não como alteração, por isso 36 das 73 notas de SCORM *Alteradas* contam como criadas. Confirmado nas operações do sv1: 478 `insert`, 37 `update` com valor anterior e 32 `update` de totais, sem ele.

**Histórico de notas:** o relatório mostra a data da recuperação (no sv1, 03/11 16:44), e não a da origem, e só a nota final. É o comportamento do Totara: `grade_object` grava `time()` em `grade_grades_history.timemodified`, sem opção de outra data. O histórico da origem não é importado (5.10), e fica no pacote como evidência. Por exemplo, `p1062252` teve 70 e depois 90 a 27/10, e no destino o histórico só mostra 90. Quem filtrar este relatório por 26 a 28/10 não vê as notas recuperadas.

**Para a Fase 5:** a compensação tem de ter em conta que (1) as linhas vazias criadas por `reserve_grade_rows()` não têm operação própria, e (2) uma operação `insert` de nota pode ter usado uma linha vazia que já existia, por isso não basta apagar a linha.

### 10.5 Quinta etapa da Fase 4: registos de eventos (plugin v0.7.0)

| Decisão | Porquê |
|---|---|
| Escrita directa em `logstore_standard_log` | A API dos eventos grava sempre a data de hoje, e `store::insert_event_entries()` é protegido e faz o mesmo `insert_records`. Esta etapa só acrescenta linhas |
| A escrita não procura eventos iguais no destino | O destino só cria eventos com a data de hoje, por isso nada novo surge no período. Há 355 eventos com a mesma chave no mesmo segundo: procurar perderia os que ficassem num lote seguinte. A idempotência vem das operações |
| `contextlevel` e `contextinstanceid` vêm do contexto do destino | Assim ficam sempre coerentes com `contextid`. O contexto de um utilizador procura-se pela conta, porque a conta pode ter sido criada depois da comparação |
| `objectid` dos eventos `scoreraw_submitted` e `status_submitted` traduzido como SCORM | Declaram `scorm_scoes_track`, mas `mod/scorm/locallib.php` grava `$scorm->id`. Tratá-los como tentativas apagaria o valor em 5 925 eventos |
| `objectid` de notas, conclusões e respostas: o registo do destino, pelas operações ou pelos *Idênticos* da comparação | Os criados depois da cópia têm na origem ids que no destino são de outros registos |
| `objectid` de `user`, `course`, `scorm`, `scorm_scoes`, `feedback`, `customcert`: pela tradução da comparação; instância da actividade do evento: confirmada em `course_modules` | São as correspondências já validadas |
| Restantes `objectid` ficam `NULL` (`report_builder` 716, `book_chapters` 604, `course_modules_completion` fora do pacote 121, `course_categories` 38, `chat_messages` 3, `forum_discussions` 2) | Não há forma de confirmar que o mesmo id é o mesmo registo. O tempo no curso e os relatórios usam utilizador, curso e data, e o evento continua a ver-se |
| `other` fica como está | É histórico, serializado de forma diferente por cada evento. Os ids aí dentro são os da origem |
| Eventos com `id` maior do que os mais recentes do destino | Os relatórios ordenam por `timecreated`. O `bi_intellidata` lê esta tabela: confirmar como detecta linhas novas (P25) |

**Quem lê estes eventos:** `blocks/dedication` e `local/edudigitalintegrationapi` usam utilizador, curso, acção e `timecreated`, por isso os primeiros e últimos acessos passam a incluir o período.

**Previsão com o pacote real (22 867 eventos):** 16 890 com `objectid` traduzido, 4 493 sem objecto, 1 484 com `objectid` vazio. 18 862 com curso. Datas entre 1793017379 e 1793216387.

**Execução no sv1 (03/11):** 22 867 criados, sem saltados nem falhados. A comparação ficou com 22 867 Idênticos, 7 260 Ignorados e 0 Novos, e os outros componentes não mudaram.

### 10.6 Sexta etapa da Fase 4: certificados (plugin v0.8.0)

| Decisão | Porquê |
|---|---|
| Escrita directa em `customcert_issues`, com `code`, `emailed` e `timecreated` da origem | O módulo não tem API para isto, e o `view.php` também insere directamente. O código mantém válidos os PDF já descarregados |
| Emissão do mesmo par no destino: fica a do destino, e nada é escrito | Decisão P12. Sem índice único, uma duplicada impede o PDF de abrir (5.9) |
| A escrita volta a confirmar o par e o código mesmo antes de gravar | O formando pode descarregar o certificado entre a comparação e a gravação. Sem índice único, a confirmação reduz esta janela mas não a elimina por completo |
| Depois de gravar, cada emissão é verificada: `template::generate_pdf(false, $userid, true)` e as duas condições de tempo do `view.php` (logs e `total_time` do SCORM), excepto para quem gere o certificado | É o que o formando vai encontrar. O PDF não é guardado nem enviado |
| Um problema não anula a emissão: fica *A rever*, com o motivo (`pdf_failed` ou `time_short`) e os valores | A emissão existia na origem. Apagá-la esconderia o problema. Repetir a etapa volta a verificar só estas |
| As confirmadas ficam com o motivo `pdf_ok` e não voltam a ser verificadas | Gerar um PDF demora, e o resultado não muda sem razão |

**Previsão com o pacote real:** 113 emissões de 77 utilizadores em 30 certificados, sem pares nem códigos repetidos, todas com `emailed = 0`. No sv1 são 113 *Novos*. No sv2, as emissões dos 4 pares da secção 5.9 mantêm-se como estão no destino.

**Execução no sv1 (03/11):** 113 emissões gravadas, sem saltados nem falhados, e 113 Idênticos na comparação. O PDF foi gerado nas 113. 111 ficaram confirmadas, e `p1062252` abriu o certificado no Totara. As 111 confirmadas passam também as duas condições de tempo com os logs e o SCORM recuperados.

**As duas emissões *A rever* (P26):** ambas `time_short`, por causa do tempo SCORM. Os logs chegam.

| Emissão | Utilizador | Curso | Exigido | SCORM no sv1 | No pacote |
|---|---|---|---|---|---|
| 51945 | `diogo.lopes` | 191 | 3 600 s | 1 826 s | 1 826 s, as mesmas 3 linhas |
| 51988 | `p1043306` | 173 | 7 200 s | 6 605 s | 4 549 s, em 2 linhas. Os outros 2 056 s são de linhas sem alterações desde a cópia, que não vêm no pacote |

O tempo recuperado está certo: o valor em falta não é uma perda da recuperação. Na origem, ambos têm o evento `course_module_viewed` do certificado, que o `view.php` só dispara depois das duas condições, e não há alterações de papéis nos logs do período. Com o mesmo tempo, a origem também os devia ter bloqueado. **Confirmado na origem a 03/11:** as linhas de tempo são as mesmas (`p1043306`: 3 008 + 1 541 + 2 056 = 6 605 s; `diogo.lopes`: 1 826 s), e o `view.php` tem a mesma verificação de `sumTime`. Os papéis também não explicam: ambos são só `student` no curso, sem papéis de sistema, categoria ou actividade, nenhum tem `mod/customcert:manage`, e o único administrador é o id 2. No código deste ambiente, só o `view.php` e a tarefa de email inserem emissões, e os certificados não têm emails activos. Resta comparar o `view.php` e o `dedication_lib.php` da origem com os daqui (SHA-256 `dbc52b5d…416b53`, 7 219 bytes, e `30ae71a9…0f51c1e`, 21 578 bytes). A causa continua por apurar. A recuperação não inventa tempo: enquanto o cliente não decidir, estes dois formandos veem o aviso de tempo em falta no destino.

### 10.7 Fase 5: compensação, relatório e revisão de segurança (plugin v0.9.0)

**Decisão do cliente (03/11): compensação por registo.** O sv2 está em uso real desde 28/10, por isso repor uma cópia apagaria o trabalho novo. A cópia fica como último recurso.

| Decisão | Porquê |
|---|---|
| Ordem inversa: certificados, logs, notas, totais, conclusões de cursos, critérios, actividades, Feedback e SCORM | Os efeitos saem antes dos dados de que dependem |
| Só desfaz um registo que ainda esteja como a recuperação o deixou, comparando-o com a linha do pacote | O que mudou entretanto é trabalho novo do destino. Fica, com o motivo `changed_since` |
| Conclusões: `helper::delete_*` e `helper::write_*` | É a API oficial: grava o registo de conclusão, com a indicação da reversão, e limpa as caches |
| Nota de actividade: limpa ou reposta por escrita directa | A API notificaria a conclusão, que perderia a data (10.4). Uma linha vazia é o estado de quem não tem nota, e pode já existir antes da recuperação, por isso não é apagada. As linhas reservadas ficam |
| Total de curso: recalculado pelo Totara | Respeita as notas bloqueadas e alteradas à mão. Volta a criar eventos `user_graded` com a data de hoje |
| SCORM e respostas substituídas ficam, com o motivo `no_previous` | O conteúdo anterior nunca é guardado (10.1, 10.2). Só existe na cópia de segurança |
| Contas criadas ficam | Podem já ter sido usadas, e apagar uma conta tem efeitos muito para além do progresso |
| Operação desfeita (`reverted`) pode voltar a ser recuperada. Mantida (`kept`) não | Recuperar sobre um registo mantido apagaria o que mudou entretanto. Uma falha (`revert_failed`) é retomada ao repetir |
| Exige a opção *Permitir a recuperação*, a capacidade, as duas confirmações e o pacote intacto | O pacote tem as linhas de comparação. Apagar o pacote impede a compensação, e o aviso de confirmação diz isso |

**Limites, também mostrados na página:** as notificações já enviadas não voltam atrás. Sem as conclusões, a sincronização normal dos grupos dinâmicos pode retirar membros e inscrições (5.11). O histórico de notas fica com as linhas da recuperação.

**Relatório final:** a página de recuperação tem o resultado acumulado, e o CSV das operações (`recover.php?export=csv`) lista cada registo com o resultado. Nunca inclui os valores anteriores. Fica registado na auditoria.

**Revisão de segurança (03/11):**

| Requisito | Verificado |
|---|---|
| Capacidade e sessão | Todas as páginas e passos exigem `local/progressrecovery:manage`, sem arquétipos. Toda a acção com escrita exige `sesskey` (o `moodleform` trata disso no carregamento) |
| Parâmetros e SQL | Parâmetros tipados. Nenhum valor externo é concatenado no SQL. O filtro por `username` vai como parâmetro |
| XSS | Os modelos não usam `{{{`. Os CSV passam por `csv_safe` contra fórmulas |
| ZIP Slip e carregamento | O pacote nunca é extraído para disco: é lido por nome, com a assinatura, a lista de ficheiros permitidos e os limites validados. Caminhos com `..`, absolutos e ligações simbólicas são recusados, com testes |
| Armazenamento | Em `dataroot`, fora do webroot, e o nome vem do UUID validado |

**Corrigido nesta revisão:** o pacote podia ser apagado durante uma recuperação, e o corte de uma mensagem de erro a meio de um carácter podia fazer falhar o passo.

**Fica em aberto (P27):** o plugin guarda dados pessoais (`username`, `ip` no pacote) e não declara um `privacy provider`. A pasta `tests/` também está vazia, porque os testes estão no Extractor (`tools/`) e são simulados, sem PHPUnit no Totara.

## 11. Riscos

| Risco | Mitigação |
|---|---|
| Colisão de IDs de registos novos (D1) | Remapeamento + chaves naturais |
| Escrita directa em tabelas do motor de perguntas e Feedback | Transacção por entidade; validação prévia de todas as FKs; testes no ambiente de teste com dados reais anonimizados |
| Eventos e observers (programas, certificações, competências) não disparados por escrita directa | Usar as APIs dos módulos para os efeitos derivados; reagregação via `completion_regular_task` |
| `customcert` 2016 sobre Totara 19 | Testar geração de PDF no ambiente de teste com o mesmo código |
| Limites de PHP no cPanel | Extracção paginada com checkpoints (pendente P2) |
| Regra de grupo dinâmico retira um utilizador após uma recuperação, anulando a inscrição | Regras analisadas: as conclusões só acrescentam membros (5.11); comparar membros e inscrições antes e depois, na cópia de homologação |
| Conta criada não ser associada ao acesso SAML, que criaria uma segunda conta | Verificado no código e na configuração (secção 4); testar um acesso real em homologação |
| Rollback impossível depois de eventos ou recálculos | Registo de operações para compensação; **backup da BD do destino obrigatório antes de cada execução em produção** |

---

## 12. Pendentes

| # | Pendente | Quem |
|---|---|---|
| ~~P1~~ | ~~Código do `mod_customcert`~~ — **resolvido a 30/10** (instalado no ambiente de teste) | — |
| ~~P2~~ | ~~PHP do cPanel~~ — **resolvido a 02/11:** PHP 8.2.35, 512M, 60 s, `ZipArchive` disponível, 172 GB livres | — |
| P3 | Versão da BD do destino (origem: MariaDB 10.11.19) | EDUdigital |
| ~~P4~~ | ~~Instante da cópia~~ — **resolvido a 02/11:** fim de 23/10, último `id` comum `8741994`. "Dados novos no destino" contam desde 24/10 00:00 (secção 9) | — |
| P21 | Antes da Fase 6, restaurar no sv1 uma cópia recente da BD do sv2 (`mysqldump --single-transaction`, sem bloquear a produção) | EDUdigital |
| ~~P20~~ | ~~Eventos de 25/10~~ — **resolvido a 02/11:** todos do `admin`, sem progresso de utilizadores reais. O pacote de 26/10 a 28/10 serve | — |
| ~~P22~~ | ~~Contas manuais com progresso~~ — **resolvido:** `fernando.gomes` e `daniela.felgueiras` são formandos, por isso o progresso é recuperado. Excluídos: `pedro.edu` e `admin` | — |
| P23 | SCORM com trabalho novo no destino fica como está, mesmo que a conclusão da actividade venha da origem. Quantificar com a cópia do sv2 antes de decidir se precisa de tratamento | EDUdigital |
| P24 | Confirmar que o cron corre no sv1, porque a sincronização dos grupos depende dele | EDUdigital |
| P26 | Duas emissões *A rever* por tempo SCORM (10.6): confirmar na origem porque foram emitidas, e o cliente decide como ficam | EDUdigital / Zurich |
| P27 | Decidir se o plugin precisa de um `privacy provider` e por quanto tempo se guardam o pacote e os resultados, antes da produção (10.7) | EDUdigital / Zurich |
| P25 | Antes da produção, confirmar se o `bi_intellidata` exporta os eventos recuperados, tanto os `user_graded` com a data da recuperação como os logs antigos com `id` novo | EDUdigital |
| ~~P5~~ | ~~Programas/Certificações~~ — **resolvido a 30/10:** apenas cursos; fora do âmbito | — |
| ~~P6~~ | ~~Tentativas de quiz em curso~~ — **resolvido a 02/11:** recuperar tal como estão quando o utilizador não tem tentativas novas no destino. Sem efeito prático por agora: 0 tentativas no período | — |
| ~~P7~~ | ~~Colisão de nº de tentativa~~ — **resolvido a 30/10** pela regra "manter o destino" (9) | — |
| ~~P8~~ | ~~Logs~~ — **resolvido a 30/10:** recuperar (5.10) | — |
| ~~P10~~ | ~~`blocks/dedication`~~ — **resolvido a 30/10** | — |
| ~~P11~~ | ~~Emissão automática de certificados~~ — **resolvido a 30/10** (sem emails configurados; tarefa inofensiva) | — |
| ~~P12~~ | ~~Emissões diferentes na origem e no destino~~ — **resolvido a 30/10:** mantém-se a emissão mais recente (destino) | — |
| ~~P13~~ | ~~Congelar a origem~~ — **resolvido a 02/11:** a origem não está a ser usada e não há progresso alterado depois do período. A extracção compara as contagens com as do diagnóstico e assinala diferenças | — |
| ~~P14~~ | ~~Lista completa das emissões~~ — **resolvido:** segue em `certificates/issues.ndjson` no pacote | — |
| ~~P15~~ | ~~Quiz e Assignment~~ — **resolvido a 02/11:** recuperação dispensada | — |
| ~~P16~~ | ~~Utilizadores em falta~~ — **resolvido a 02/11:** criar as contas na recuperação, com `auth = saml2` (secção 4) | — |
| ~~P17~~ | ~~Inscrições em falta~~ — **resolvido a 02/11:** seguir o modelo de grupos globais dinâmicos; o plugin não cria inscrições (5.11) | — |
| ~~P18~~ | ~~Regras dos grupos~~ — **resolvido a 02/11** (5.11) | — |
| ~~P19~~ | ~~`auth_saml2` e inscrições dos cursos 163, 164 e 174~~ — **resolvido a 02/11** (secção 4 e 5.11) | — |

---

## 13. Próximo passo

- **Fase 1 — Extractor:** concluída e validada a 02/11 com os dados reais no cPanel (MariaDB 10.11.19), com as contagens iguais às do diagnóstico. Falta P20.
- **Fase 2:** validada a 02/11 no sv1 com o pacote real (assinatura válida, *Compatível*, 0 pendentes).
- **Fase 3 — motor de comparação:** validada a 02/11 no sv1 com o pacote real (plugin v0.2.0). As 78 604 linhas do relatório batem certo com o diagnóstico em todos os componentes (645, 1 207, 485, 557, 34 614 + 10 581, 190, 113, 30 127). 0 inválidos e 0 conflitos. 0 *Destino mais recente*, como esperado num servidor parado a 24/10: esta regra só é exercida na Fase 6. Três contas a criar no sv1 (`p1017706`, `p1042465`, `p1062252`); no sv2 será só `p1042465`.
- **Inscrições pendentes no sv1:** 85, de 36 utilizadores. 35 têm a ficha concluída no pacote, o que confirma o modelo de 5.11. Só `filipa.cardoso` (curso 206) não tem inscrição nem ficha: 2 linhas SCORM alteradas e 10 iguais. O progresso é recuperado e fica listado como pendente. **Esclarecido a 03/11:** é gestora e testa cursos, por isso não é uma formanda sem acesso, e a linha pendente é informativa.
- **Fase 4 — recuperação:** etapa 1 (contas e fichas, v0.3.0) validada a 03/11 no sv1. Etapa 2 (tentativas SCORM, v0.4.0) validada a 03/11 no sv1. Etapa 3 (Feedback e conclusões, v0.5.1) validada a 03/11 no sv1. Etapa 4 (notas, v0.6.2) validada a 03/11 no sv1, a partir da cópia anterior à etapa (10.4). Etapa 5 (logs, v0.7.0) executada no sv1, com os números previstos (10.5). Etapa 6 (certificados, v0.8.0) validada a 03/11 no sv1, com duas emissões a rever (10.6, P26). 
- **Fase 5 — segurança e auditoria:** compensação, relatório e revisão de segurança implementados (v0.9.0), por validar no sv1 (10.7). Segue-se a Fase 6, numa cópia recente do sv2 (P21).
