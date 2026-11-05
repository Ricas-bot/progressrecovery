<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Strings em português de Portugal.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Recuperação de progresso';
$string['progressrecovery:manage'] = 'Gerir a recuperação de progresso';
$string['settings'] = 'Recuperação de progresso: definições';
$string['hmacsecret'] = 'Segredo partilhado';
$string['hmacsecret_desc'] = 'O segredo apresentado na página de configuração do Totara Recovery Extractor. Sem ele, nenhum pacote é aceite.';
$string['maxpackagemb'] = 'Tamanho máximo do pacote (MB)';
$string['maxpackagemb_desc'] = 'Os pacotes maiores são rejeitados. Os limites de carregamento do PHP e do Totara também se aplicam.';

$string['lastpackage'] = 'Último pacote';
$string['nopackage'] = 'Ainda não foi carregado nenhum pacote.';
$string['nosecret'] = 'O segredo partilhado não está configurado: nenhum pacote pode ser aceite. Configure-o nas definições do plugin.';
$string['file'] = 'Ficheiro';
$string['status'] = 'Estado';
$string['compatibility'] = 'Compatibilidade';
$string['sourceversion'] = 'Versão da origem';
$string['exported'] = 'Data da exportação';
$string['period'] = 'Período recuperado';
$string['totalusers'] = 'Utilizadores envolvidos';
$string['totalcourses'] = 'Cursos';
$string['totalactivities'] = 'Actividades';
$string['pendingissues'] = 'Erros e avisos pendentes';
$string['lastrecovery'] = 'Última recuperação';
$string['norecoveryyet'] = 'Nenhuma';
$string['history'] = 'Histórico de pacotes';
$string['uploaded'] = 'Carregado em';
$string['size'] = 'Tamanho';
$string['rejections'] = 'Carregamentos rejeitados';
$string['date'] = 'Data';
$string['operation'] = 'Operação';
$string['reason'] = 'Motivo';
$string['failed'] = 'Falhou';

$string['upload'] = 'Carregar pacote';
$string['uploadintro'] = 'Carregue o ficheiro ZIP gerado pelo Extractor (máximo {$a}). O pacote é validado antes de qualquer processamento e nenhum dado académico é alterado.';
$string['packagefile'] = 'Pacote de recuperação';
$string['uploadvalidate'] = 'Carregar e validar';
$string['uploadaccepted'] = 'Pacote validado e guardado na área privada.';
$string['uploadrejected'] = 'Pacote rejeitado. Nada foi guardado.';
$string['uploadduplicate'] = 'Este pacote já foi carregado. Para o carregar de novo, apague primeiro o existente.';
$string['uploadfailed'] = 'Não foi possível receber o ficheiro. Tente novamente.';
$string['error_storage'] = 'Não foi possível guardar o pacote na área privada.';

$string['manifest'] = 'Manifesto';
$string['packageid'] = 'Identificador do pacote';
$string['sourceidentifier'] = 'Identificador da origem';
$string['sourceprefix'] = 'Prefixo das tabelas na origem';
$string['extractor'] = 'Versão do Extractor';
$string['packageformat'] = 'formato';
$string['component'] = 'Componente';
$string['rowsinperiod'] = 'Registos no período';
$string['notes'] = 'Observações';
$string['integrity'] = 'Integridade';
$string['content'] = 'Conteúdo';
$string['table'] = 'Tabela';
$string['role_changed'] = 'Alterados';
$string['role_dependency'] = 'Dependências';
$string['role_structure'] = 'Estrutura';
$string['role_evidence'] = 'Evidência';
$string['back'] = 'Voltar';
$string['deletepackage'] = 'Apagar pacote';
$string['deleteconfirm'] = 'Apagar o ficheiro do pacote «{$a}»? O registo mantém-se no histórico. Sem o ficheiro, deixa de ser possível desfazer a recuperação.';
$string['packagedeleted'] = 'Pacote apagado.';

$string['status_validated'] = 'Validado';
$string['status_deleted'] = 'Apagado';
$string['compat_compatible'] = 'Compatível';
$string['compat_warnings'] = 'Compatível com avisos';
$string['compat_manual'] = 'Necessita de intervenção manual';
$string['compat_incompatible'] = 'Incompatível';

$string['component_activity_completion'] = 'Conclusão de actividades';
$string['component_course_completion'] = 'Conclusão de cursos';
$string['component_grades'] = 'Notas';
$string['component_quiz'] = 'Quiz';
$string['component_assign'] = 'Assignment';
$string['component_scorm'] = 'SCORM';
$string['component_feedback'] = 'Feedback';
$string['component_customcert'] = 'Custom Certificate';
$string['component_logs'] = 'Registos de eventos';

$string['reason_totara_version'] = 'Versão do Totara diferente (origem / destino): {$a}.';
$string['reason_totara_build'] = 'Build do Totara diferente (origem / destino): {$a}.';
$string['reason_core_version'] = 'Versão do core diferente (origem / destino): {$a}.';
$string['reason_site_identifier'] = 'O identificador da origem não é o desta instalação: confirme que esta plataforma é uma cópia da origem.';
$string['reason_timezone'] = 'Fuso horário diferente (origem / destino): {$a}.';
$string['reason_not_recovered'] = 'Recuperação dispensada, mas o pacote traz {$a} registos: é preciso uma decisão.';
$string['reason_no_data'] = 'Sem dados no período.';
$string['reason_plugin_missing'] = 'Plugin em falta neste destino: {$a}.';
$string['reason_plugin_version'] = 'Versão do plugin diferente (origem / destino) em {$a}.';
$string['reason_log_store'] = 'O armazenamento logstore_standard não está activo neste destino.';

$string['check_extension'] = 'Extensão .zip.';
$string['check_size'] = 'Tamanho dentro do limite.';
$string['check_real_type'] = 'O conteúdo é um arquivo ZIP.';
$string['check_archive_names'] = 'Nomes das entradas seguros.';
$string['check_required_files'] = 'Manifesto, checksums e assinatura presentes.';
$string['check_signature'] = 'Assinatura HMAC-SHA256 válida.';
$string['check_checksums'] = 'Checksums SHA-256 correctos ({$a} ficheiros).';
$string['check_manifest'] = 'Manifesto válido.';
$string['check_rows'] = 'Estrutura e contagens das linhas correctas.';

$string['checkfail_extension'] = 'O ficheiro não tem a extensão .zip.';
$string['checkfail_size'] = 'O ficheiro está vazio ou excede o limite ({$a} bytes).';
$string['checkfail_real_type'] = 'O conteúdo do ficheiro não é um arquivo ZIP válido.';
$string['checkfail_archive_entries'] = 'O arquivo está vazio ou tem demasiadas entradas.';
$string['checkfail_archive_names'] = 'Nome de entrada não permitido ou repetido: {$a}.';
$string['checkfail_archive_special'] = 'Entrada cifrada ou ligação simbólica: {$a}.';
$string['checkfail_archive_bomb'] = 'O conteúdo descomprimido é desproporcionado: {$a}.';
$string['checkfail_required_files'] = 'Faltam o manifesto, os checksums ou a assinatura.';
$string['checkfail_secret_missing'] = 'O segredo partilhado não está configurado.';
$string['checkfail_signature'] = 'Assinatura inválida: o pacote não foi gerado com o segredo configurado, ou foi alterado.';
$string['checkfail_checksums'] = 'Falha de checksum: {$a}.';
$string['checkfail_unlisted_file'] = 'Ficheiro fora da lista autorizada: {$a}.';
$string['checkfail_table_not_allowed'] = 'Tabela não permitida no pacote: {$a}.';
$string['checkfail_manifest'] = 'Manifesto inválido: {$a}.';
$string['checkfail_rows'] = 'Linhas inválidas ou contagem incorrecta: {$a}.';

$string['audit_package_accepted'] = 'Pacote aceite';
$string['audit_package_rejected'] = 'Pacote rejeitado';
$string['audit_package_duplicate'] = 'Pacote repetido';
$string['audit_package_deleted'] = 'Pacote apagado';

$string['copyepoch'] = 'Instante da cópia (epoch)';
$string['copyepoch_desc'] = 'Registos do destino com data igual ou posterior contam como dados novos do destino, e nesse caso a origem não os substitui. 1792796400 = 24/10/2026 00:00 (Lisboa).';
$string['excludedusers'] = 'Utilizadores excluídos';
$string['excludedusers_desc'] = 'Nomes de utilizador cujos dados nunca são recuperados (contas de teste ou de administração), um por linha. Aplica-se a cada nova comparação.';
$string['error_package_changed'] = 'O ficheiro do pacote em armazenamento já não corresponde ao que foi validado.';
$string['error_compare_step'] = 'A comparação parou. Consulte o registo de erros do servidor e use Retomar.';
$string['busy'] = 'Outro pedido está a processar esta comparação.';

$string['compare'] = 'Comparar com esta instalação';
$string['comparison'] = 'Comparação';
$string['comparisonintro'] = 'A comparação só lê os dados do Totara: nenhum dado académico é alterado. Usa as definições actuais de instante da cópia e de utilizadores excluídos.';
$string['startcompare'] = 'Iniciar comparação';
$string['restartcompare'] = 'Repetir comparação';
$string['resume'] = 'Retomar';
$string['comparing'] = 'A comparar';
$string['records'] = 'registos';
$string['copytime'] = 'Instante da cópia';
$string['excluded'] = 'Utilizadores excluídos';
$string['finished'] = 'Concluída em';
$string['blocked'] = 'Componentes não comparados, por serem incompatíveis';
$string['found'] = 'Na origem';
$string['indestination'] = 'Existentes no destino';
$string['class_new'] = 'Novos';
$string['class_identical'] = 'Idênticos';
$string['class_changed'] = 'Alterados';
$string['class_conflict'] = 'Conflitos';
$string['class_invalid'] = 'Inválidos';
$string['class_dest_newer'] = 'Destino mais recente';
$string['class_ignored'] = 'Ignorados';
$string['class_pending_enrol'] = 'Inscrição pendente';
$string['component_enrolment'] = 'Inscrições';
$string['keyfacts'] = 'A reter';
$string['registrations'] = 'Fichas de inscrição a recuperar';
$string['accountstocreate'] = 'Contas a criar';
$string['pendingviagroup'] = 'Inscrições pendentes, a tratar pelos grupos dinâmicos';
$string['pendingnotgroup'] = 'Inscrições pendentes fora dos grupos dinâmicos, a rever';
$string['entitiesattention'] = 'Estruturas que precisam de atenção';
$string['entity'] = 'Tipo';
$string['naturalkey'] = 'Identificador';
$string['entitystatus'] = 'Situação';
$string['entitystatus_missing'] = 'Em falta';
$string['entitystatus_manual'] = 'Decisão manual';
$string['entitystatus_invalid'] = 'Inválida';
$string['entitystatus_excluded'] = 'Excluída';
$string['usersremapped'] = 'Utilizadores com ID diferente no destino (traduzidos por username)';
$string['details'] = 'Detalhe por registo';
$string['filter'] = 'Filtrar';
$string['all'] = 'Todos';
$string['username'] = 'Utilizador';
$string['course'] = 'Curso';
$string['classification'] = 'Classificação';
$string['sourceid'] = 'ID na origem';
$string['destid'] = 'ID no destino';
$string['noitems'] = 'Nenhum registo com estes filtros.';
$string['exportcsv'] = 'Exportar CSV';
$string['exportjson'] = 'Exportar JSON';

$string['itemreason_excluded_user'] = 'Utilizador excluído';
$string['itemreason_fills_completion'] = 'Concluído na origem e por concluir no destino';
$string['itemreason_enrolment_only'] = 'Só de inscrição: o Totara cria esta linha';
$string['itemreason_user_to_create'] = 'Exige criar a conta';
$string['itemreason_dest_locked'] = 'Nota bloqueada ou alterada manualmente no destino';
$string['itemreason_scorm_dest_newer'] = 'SCORM recomeçado no destino: a conclusão e a nota seguem a tentativa do destino';
$string['itemreason_would_regress'] = 'Passaria de concluído a incompleto';
$string['itemreason_values_differ'] = 'Respostas diferentes com a mesma data';
$string['itemreason_anonymous_other_response'] = 'Já existe outra resposta anónima deste utilizador';
$string['itemreason_code_differs'] = 'Emissão com outro código no destino: mantém-se a do destino';
$string['itemreason_code_in_use'] = 'O código já está noutra emissão';
$string['itemreason_template_differs'] = 'O modelo do certificado é diferente no destino';
$string['itemreason_no_account'] = 'Utilizador sem conta no destino';
$string['itemreason_via_group'] = 'Inscrito por grupo na origem';
$string['itemreason_not_via_group'] = 'Sem inscrição por grupo na origem';
$string['itemreason_dep_user'] = 'Utilizador inválido ou a rever';
$string['itemreason_dep_course'] = 'Curso sem correspondência';
$string['itemreason_dep_course_modules'] = 'Actividade sem correspondência';
$string['itemreason_dep_course_completion_criteria'] = 'Critério de conclusão sem correspondência';
$string['itemreason_dep_grade_items'] = 'Item de nota sem correspondência';
$string['itemreason_dep_scorm'] = 'SCORM sem correspondência ou com outro pacote';
$string['itemreason_dep_sco'] = 'SCO sem correspondência';
$string['itemreason_dep_feedback'] = 'Inquérito sem correspondência';
$string['itemreason_dep_feedback_item'] = 'Pergunta do inquérito diferente no destino';
$string['itemreason_dep_customcert'] = 'Certificado sem correspondência';
$string['itemreason_dep_context'] = 'Contexto sem correspondência';

$string['audit_compare_started'] = 'Comparação iniciada';
$string['audit_compare_finished'] = 'Comparação concluída';
$string['audit_compare_exported'] = 'Relatório exportado';

$string['itemreason_system_event'] = 'Evento sem utilizador real: não conta para o tempo no curso';
$string['allowrecovery'] = 'Permitir a recuperação nesta instalação';
$string['allowrecovery_desc'] = 'Sem esta opção, o plugin só valida e compara. Active-a apenas na instalação onde a recuperação foi aprovada.';
$string['recovery'] = 'Recuperação';
$string['recoveryintro'] = 'A recuperação faz-se por etapas, cada uma validada antes da seguinte. Mostra-se apenas o que ainda falta.';
$string['recoveryfichas'] = 'Fichas de inscrição';
$string['recoverybackup'] = 'Confirmo que tenho uma cópia de segurança recente da base de dados desta instalação.';
$string['recovering'] = 'A recuperar:';
$string['recoveryresult'] = 'Resultado';
$string['recoverynotes'] = 'Registos saltados, falhados ou diferentes da origem';
$string['recoverygroups'] = 'Grupos e inscrições';
$string['recoverygroupsintro'] = 'As inscrições dependem da tarefa de sincronização, executada pelo cron. Recarregue esta página para ver os valores actuais.';
$string['cohort'] = 'Grupo';
$string['membersbefore'] = 'Membros antes';
$string['membersnow'] = 'Membros agora';
$string['pendingwaiting'] = 'Inscrições pendentes ainda em falta:';
$string['opcreated'] = 'Criados';
$string['opupdated'] = 'Actualizados';
$string['opstatus_done'] = 'Feito';
$string['opstatus_skipped'] = 'Saltados';
$string['opstatus_failed'] = 'Falhados';
$string['error_recovery_disabled'] = 'A recuperação não está permitida nesta instalação. Active-a nas definições do plugin, se foi aprovada.';
$string['error_recovery_backup'] = 'Confirme que tem uma cópia de segurança recente antes de iniciar.';
$string['error_recovery_compare'] = 'Conclua primeiro a comparação deste pacote.';
$string['error_recovery_running'] = 'Já há uma recuperação em curso para este pacote.';
$string['error_recovery_step'] = 'A recuperação parou. Consulte o registo de erros do servidor e use Retomar.';
$string['error_recovery_state'] = 'O estado da conclusão na origem não é válido para o Totara.';
$string['error_recovery_target'] = 'O registo do destino pertence a outro inquérito ou utilizador.';
$string['audit_recovery_started'] = 'Recuperação iniciada';
$string['audit_recovery_finished'] = 'Recuperação concluída';
$string['audit_recovery_failed'] = 'Recuperação interrompida';
$string['scope_registration'] = 'Etapa 1: contas e fichas de inscrição';
$string['scope_registration_intro'] = 'Cria as contas em falta e recupera a resposta ao inquérito, a conclusão da actividade, os critérios e a conclusão do curso. Depois coloca em fila a sincronização dos grupos, que dá acesso aos cursos.';
$string['scope_registration_start'] = 'Recuperar as fichas';
$string['scope_scorm'] = 'Etapa 2: tentativas SCORM';
$string['scope_scorm_intro'] = 'Recupera as linhas das tentativas com as datas da origem. As notas e as conclusões ficam para as etapas seguintes, por isso ainda não mudam nesta etapa. Se o destino tem trabalho novo no mesmo SCORM, esse utilizador fica como está.';
$string['scope_scorm_start'] = 'Recuperar as tentativas SCORM';
$string['remainingnew'] = 'Novos por recuperar';
$string['remainingchanged'] = 'Alterados por recuperar';
$string['stagenothingleft'] = 'Nada por fazer nesta etapa.';
$string['error_recovery_order'] = 'Conclua primeiro a etapa anterior.';
$string['scope_completions'] = 'Etapa 3: Feedback e conclusões';
$string['scope_completions_intro'] = 'Recupera, nos restantes cursos, as respostas aos inquéritos, as conclusões de actividades, os critérios e as conclusões de cursos, com as datas da origem. O que já está concluído no destino mantém-se.';
$string['scope_completions_start'] = 'Recuperar o Feedback e as conclusões';
$string['scope_grades'] = 'Etapa 4: notas';
$string['scope_grades_intro'] = 'O Totara calcula as notas a partir das tentativas SCORM recuperadas, com as datas da origem, e depois os totais dos cursos. O resultado é comparado com a origem. As notas bloqueadas ou alteradas à mão no destino mantêm-se.';
$string['scope_grades_start'] = 'Recuperar as notas';
$string['scope_logs'] = 'Etapa 5: registos de eventos';
$string['scope_logs_intro'] = 'Recupera os eventos do período com a data original e os utilizadores do destino, para o tempo no curso e os relatórios de actividade. Só acrescenta linhas: não altera eventos, notas nem conclusões.';
$string['scope_logs_start'] = 'Recuperar os registos de eventos';
$string['scope_certificates'] = 'Etapa 6: certificados';
$string['scope_certificates_intro'] = 'Recupera as emissões com o código e a data originais, por isso os certificados já descarregados continuam válidos. Uma emissão que já exista no destino nunca é duplicada. Depois confirma, para cada emissão, o tempo exigido e a geração do PDF, sem o guardar nem enviar.';
$string['scope_certificates_start'] = 'Recuperar os certificados';
$string['opstatus_review'] = 'A rever';
$string['opstatus_kept'] = 'Mantidos pela compensação';
$string['recoveryexport'] = 'Descarregar o relatório das operações (CSV)';
$string['compensation'] = 'Desfazer a recuperação';
$string['compensating'] = 'A desfazer';
$string['compensationintro'] = 'Desfaz as escritas da recuperação pela ordem inversa das etapas. Só toca num registo que ainda esteja como a recuperação o deixou. O que mudou entretanto fica como está e é listado.';
$string['compensationlimits'] = 'Não desfaz: as contas criadas, as respostas de inquérito e as linhas SCORM substituídas, cujo conteúdo anterior nunca é guardado e só existe na cópia de segurança, nem as notificações já enviadas. As notas e os totais voltam a ser recalculados. Sem as conclusões, a sincronização normal dos grupos dinâmicos pode retirar membros e inscrições.';
$string['compensationplan'] = 'Plano e resultado';
$string['compensationrevert'] = 'Por desfazer';
$string['compensationirreversible'] = 'Sem valor anterior';
$string['compensationreverted'] = 'Desfeitos';
$string['compensationkept'] = 'Mantidos';
$string['compensationnotes'] = 'Registos mantidos';
$string['compensationunderstood'] = 'Compreendo o que é e o que não é desfeito.';
$string['compensationstart'] = 'Desfazer a recuperação';
$string['error_compensation_confirm'] = 'Confirme que compreende o que é e o que não é desfeito.';
$string['stagereview'] = 'Emissões gravadas em que o formando ainda não consegue obter o certificado. Repetir a etapa volta a verificá-las:';
$string['error_recovery_issue_exists'] = 'O destino já tem esta emissão ou este código.';
$string['error_recovery_pdf'] = 'O módulo não gerou um PDF válido.';
$string['error_recovery_log_context'] = 'O contexto do evento não existe no destino.';
$string['opstatus_differs'] = 'Diferentes da origem';
$string['error_recovery_no_attempt'] = 'O utilizador não tem tentativas SCORM no destino.';
$string['error_recovery_regrade'] = 'O Totara não conseguiu calcular a nota.';
$string['error_recovery_grade_type'] = 'Este tipo de nota não é recuperado nesta etapa.';
