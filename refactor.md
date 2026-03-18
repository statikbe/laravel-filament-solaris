
- Add class documentation to: 
  - ComponentFactory
  - PromptBuilder
- Write readme. Use clear, technical documentation, if you want to add diagrams use mermaidjs. No marketing speak.
  - Add architecture section for devs wanting to extend things - maybe with a class diagram

- refactor the prompt builder:
  - probably implement a superclass with shared logic
  - understand/fix locale retrieval Filament v4 has specific functions to get the current locale of the form or table
- closures toevoegen voor setters
- queued actions


Problems with vibe coding voor package:
- Geen algemene kritische blik:
  - zelfs niet na Claude een review te laten doen komen obvious structurele issues niet naar boven
  - na vragen stellen hoe de flow werkt: hallucinatie dat standaard laravel framework classes (afhankelijk zijn van een third package)
- handje vastnemen bij voorgestelde refactor en alles in vraag stellen
- kritisch blijven bij free code
- pull items up to abstract class of abstract classes maken obv implementatie wordt niet gedaan
- dingen die in de initiele spec files beschreven staan zijn niet geimplementeerd. Enkel stubs.
  - ligt het aan de specs of aan het plan? 
