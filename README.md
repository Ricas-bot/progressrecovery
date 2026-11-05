# Recuperação de progresso (`local_progressrecovery`)

Plugin local para Totara 19, instalado no servidor de destino. Recebe, valida, compara e recupera os dados
exportados pelo **Totara Recovery Extractor**.

> **Estado (03/11/2026):** Fases 2 e 3 validadas no sv1. Fase 4 em curso: as etapas 1 a 4 (contas e fichas, tentativas SCORM, Feedback e conclusões, notas) estão validadas no sv1, e as etapas 5 e 6 (registos de eventos e certificados) foram executadas no sv1. Fase 5 validada no sv1. Segue-se a Fase 6, numa cópia recente do sv2. **O plugin só altera dados académicos com a opção *Permitir a recuperação nesta instalação* activa, desligada por omissão.**

## Instalação

1. Copie a pasta para `server/local/progressrecovery`.
2. Entre como administrador e abra *Administração do site → Notificações*, para instalar.
3. Em *Administração do site → Plugins → Plugins locais → Recuperação de progresso: definições*:
   - cole o **segredo partilhado** apresentado na página *Configuração* do Extractor;
   - confirme o tamanho máximo do pacote (por omissão, 512 MB);
   - confirme o instante da cópia e os utilizadores excluídos;
   - deixe *Permitir a recuperação nesta instalação* desligada, excepto onde a recuperação foi aprovada.
4. Confirme que os limites `upload_max_filesize` e `post_max_size` do PHP, e o limite de carregamento do Totara, são maiores do que o pacote.

## Permissões

A capacidade `local/progressrecovery:manage` não é atribuída a nenhum papel por omissão. Os administradores do site têm-na sempre.
Atribua-a apenas a quem for explicitamente autorizado.

## Utilização

*Administração do site → Plugins → Plugins locais → Recuperação de progresso*.

| Página | O que faz |
|---|---|
| Painel | Estado do último pacote, histórico e carregamentos rejeitados |
| Carregar pacote | Recebe o ZIP e valida-o |
| Detalhe do pacote | Manifesto, compatibilidade por componente, integridade, conteúdo e remoção |
| Comparação | Compara o pacote com esta instalação, por lotes retomáveis. Relatório por componente, com filtros e exportação em CSV e JSON |
| Recuperação | Plano, aprovação com confirmação da cópia de segurança, execução por lotes retomáveis, resultado e estado dos grupos |

## Validação de um pacote

Pára na primeira falha e nada é guardado se falhar. O pacote nunca é extraído para o disco: é lido por streams a partir do ZIP.

1. Extensão `.zip`, tamanho e tipo real do ficheiro.
2. Arquivo seguro: nomes das entradas, sem ligações simbólicas, sem cifra, sem expansão desproporcionada.
3. Assinatura HMAC-SHA256 com o segredo partilhado.
4. Checksums SHA-256 de todos os ficheiros. Nenhum ficheiro fora da lista assinada é aceite.
5. Manifesto: formato, identificador, período e lista fechada de tabelas e componentes.
6. Cada linha é JSON, pertence à tabela do ficheiro e as contagens batem certo.

Depois é classificada a compatibilidade por componente: *Compatível*, *Compatível com avisos*, *Necessita de intervenção manual* ou
*Incompatível*. Um pacote já carregado não é aceite outra vez sem o apagar primeiro.

Os pacotes aceites ficam em `<dataroot>/local_progressrecovery/packages/`, fora do webroot, com o nome gerado pelo plugin.

## Comparação

Classifica cada registo como *Novo*, *Idêntico*, *Alterado*, *Conflito*, *Inválido*, *Destino mais recente* ou *Ignorado*, sem alterar dados académicos:

- Os utilizadores são traduzidos por `username`: o `id` de outra pessoa nunca é usado.
- Se o destino já tem dados novos para o mesmo utilizador e a mesma actividade, ficam os do destino. A origem só preenche o que falta.
- Os cursos e actividades têm de coincidir no `id` e no identificador natural.
- As fichas de inscrição são lidas das regras dos grupos dinâmicos. O plugin não cria inscrições.
- Os resultados nunca guardam respostas de inquéritos nem conteúdo SCORM.
- Os eventos sem utilizador real (visitantes, sistema, envio de emails) são ignorados.

## Recuperação

Faz-se por etapas, cada uma validada antes da seguinte. A página mostra o resultado acumulado e só o que ainda falta.

- **Etapa 1, contas e fichas:** cria as contas em falta e recupera a resposta ao inquérito, a conclusão da actividade, os critérios e a conclusão do curso. As fichas são os cursos usados nas regras dos grupos dinâmicos. Por fim, coloca em fila a sincronização dos grupos, que o cron executa.
- **Etapa 2, tentativas SCORM:** recupera as linhas de `scorm_scoes_track` com as datas da origem, por escrita directa, porque `scorm_insert_track()` gravaria a data de hoje e dispararia notas e conclusões. Estas ficam para as etapas seguintes.
- **Etapa 3, Feedback e conclusões:** recupera os restantes cursos, com as datas da origem, antes de se regenerarem as notas. O que já está concluído no destino mantém-se.
- **Etapa 4, notas:** o Totara calcula as notas a partir das tentativas recuperadas, com as datas da origem, e depois os totais. O resultado é comparado com a origem, e as diferenças ficam assinaladas.
- **Etapa 5, registos de eventos:** acrescenta os eventos do período a `logstore_standard_log`, com a data original e os ids do destino. Quando não se confirma que o `objectid` é o mesmo registo, fica vazio.
- **Etapa 6, certificados:** recupera as emissões com o código e a data originais, sem nunca duplicar uma emissão do destino. Depois confirma, para cada emissão, o tempo exigido e a geração do PDF. Se algo falhar, a emissão fica *A rever*, e repetir a etapa volta a verificá-la.

**Desfazer a recuperação** (página *Desfazer a recuperação*): reverte as escritas por ordem inversa, só nos registos que continuam como a recuperação os deixou. Não desfaz as contas criadas, as respostas de inquérito e as linhas SCORM substituídas, nem as notificações já enviadas. Precisa do ficheiro do pacote.

Em todas as etapas:

- Só é tratado o que a comparação classificou como *Novo* ou *Alterado*. Cada registo volta a ser classificado no momento da escrita: se o destino mudou entretanto e o registo deixou de poder ser escrito, é saltado e listado. Nos logs, esta verificação confirma só o utilizador e o contexto, porque o destino não cria eventos com datas passadas.
- Cada registo tem a sua operação em `local_progressrecovery_ops`. Uma interrupção nunca duplica nada e uma falha num registo não impede os outros. Repetir a recuperação retoma os saltados e os falhados.
- As conclusões usam a API `core_completion\helper`. Não são disparados eventos de conclusão: os grupos lêem directamente `course_completions`.
- O plugin nunca cria inscrições. O relatório mostra os membros de cada grupo antes e agora, e as inscrições pendentes ainda em falta.
- As operações guardam a linha anterior das conclusões, para compensação. As respostas de inquéritos e os valores SCORM substituídos nunca são guardados: a reposição faz-se pela cópia de segurança.

## Testes

`tools/plugintest.php`, no projecto do Extractor, gera pacotes reais numa BD SQLite descartável e valida-os com as classes deste plugin,
sem base de dados do Totara. `tools/comparetest.php` faz o mesmo para a comparação, com um destino simulado, e `tools/recoverytest.php` para a recuperação,
com o `core_completion\helper` real:

```
php tools/plugintest.php C:/wamp64/www/totara/server/local/progressrecovery
php tools/comparetest.php C:/wamp64/www/totara/server/local/progressrecovery
php tools/recoverytest.php C:/wamp64/www/totara/server/local/progressrecovery
```

## Documentação

[docs/fase0-analise-tecnica.md](docs/fase0-analise-tecnica.md): esquema real, decisões do cliente, regras dos grupos dinâmicos e riscos.
